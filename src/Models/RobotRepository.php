<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\NotFoundException;
use App\Factories\RobotFactory;
use PDO;

class RobotRepository
{
    /** Applied when no access filter is supplied (CLI tooling, tests, seeding). */
    public const UNRESTRICTED = ['sql' => 'TRUE', 'params' => []];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array{sql: string, params: array<string, mixed>} $access from AccessPolicy::robotFilter()
     * @param array{arena_id?: int, status?: string, type?: string}  $filters view filters
     * @return list<BaseRobot>
     */
    public function getAll(
        int $limit = 50,
        int $offset = 0,
        array $access = self::UNRESTRICTED,
        array $filters = [],
    ): array {
        [$where, $params] = $this->buildWhere($access, $filters);

        $sql = "SELECT r.* FROM robots r WHERE {$where} ORDER BY r.id ASC LIMIT :limit OFFSET :offset";
        $stmt = $this->bind($sql, $params + ['limit' => $limit, 'offset' => $offset]);

        return array_map(fn (array $row) => RobotFactory::create($row), $stmt->fetchAll());
    }

    /**
     * @param array{sql: string, params: array<string, mixed>} $access
     * @param array{arena_id?: int, status?: string, type?: string} $filters
     */
    public function count(array $access = self::UNRESTRICTED, array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($access, $filters);

        return (int) $this->bind("SELECT COUNT(*) FROM robots r WHERE {$where}", $params)->fetchColumn();
    }

    /** @param array{sql: string, params: array<string, mixed>} $access */
    public function find(int $id, array $access = self::UNRESTRICTED): ?BaseRobot
    {
        $sql  = "SELECT r.* FROM robots r WHERE r.id = :id AND {$access['sql']}";
        $stmt = $this->bind($sql, $access['params'] + ['id' => $id]);
        $row  = $stmt->fetch();

        return $row === false ? null : RobotFactory::create($row);
    }

    /** @param array{sql: string, params: array<string, mixed>} $access */
    public function findOrFail(int $id, array $access = self::UNRESTRICTED): BaseRobot
    {
        return $this->find($id, $access) ?? throw NotFoundException::robot($id);
    }

    /**
     * Robots that could actually take a given task, in one query: access scope,
     * bookable status, the required capability, and battery headroom.
     *
     * This is what replaces the guess-and-read-the-409 workflow.
     *
     * @param array{sql: string, params: array<string, mixed>} $access
     * @return list<array<string, mixed>>
     */
    public function eligibleForTask(
        int $taskId,
        array $access = self::UNRESTRICTED,
        int $limit = 50,
        int $offset = 0,
        ?string $startTime = null,
        ?string $endTime = null,
    ): array {
        $unavailable = self::statusList(RobotStatus::unavailable());

        $sql = "SELECT r.*, t.title AS task_title, t.min_battery_level
                FROM robots r
                CROSS JOIN tasks t
                WHERE t.id = :task_id
                  AND {$access['sql']}
                  AND r.status NOT IN ({$unavailable})
                  AND COALESCE(r.battery_level, 0) >= t.min_battery_level
                  AND (
                        t.required_capability_id IS NULL
                        OR EXISTS (
                            SELECT 1 FROM robot_capabilities rc
                            WHERE rc.robot_id = r.id
                              AND rc.capability_id = t.required_capability_id
                        )
                  )
                  -- The duty ledger, on the same terms the scheduler applies it:
                  -- the return reserve is not available to book against. Without
                  -- this the endpoint offered robots that scheduleTask() then
                  -- refused, which is exactly the guess-and-read-the-409 loop the
                  -- endpoint exists to remove.
                  AND (r.max_duty_minutes - r.return_reserve_minutes - r.duty_minutes_used)
                        >= t.estimated_duration
                  -- And the per-booking cap, so a task longer than any single
                  -- booking may be does not appear bookable on any robot.
                  AND t.estimated_duration <= :max_booking_minutes";

        $params = $access['params'] + [
            'task_id'             => $taskId,
            'max_booking_minutes' => Schedule::MAX_BOOKING_MINUTES,
        ];

        // When a window is supplied, drop robots already booked across it.
        if ($startTime !== null && $endTime !== null) {
            $sql .= " AND NOT EXISTS (
                          SELECT 1 FROM schedules s
                          WHERE s.robot_id = r.id
                            AND s.status <> 'cancelled'
                            AND (s.start_time, s.end_time) OVERLAPS
                                (CAST(:win_start AS timestamp), CAST(:win_end AS timestamp))
                      )";
            $params['win_start'] = $startTime;
            $params['win_end']   = $endTime;
        }

        $sql .= ' ORDER BY r.battery_level DESC, r.id ASC LIMIT :limit OFFSET :offset';
        $params += ['limit' => $limit, 'offset' => $offset];

        return $this->bind($sql, $params)->fetchAll();
    }

    /**
     * Why a specific robot is not eligible -- one reason per failed gate, so an
     * operator gets a complete answer rather than the first failure.
     *
     * @return list<string> empty when the robot is eligible
     */
    public function ineligibilityReasons(int $robotId, int $taskId): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.status,
                    COALESCE(r.battery_level, 0) AS battery_level,
                    t.min_battery_level,
                    t.required_capability_id,
                    t.estimated_duration,
                    r.max_duty_minutes,
                    r.return_reserve_minutes,
                    r.duty_minutes_used,
                    r.duty_class,
                    c.name AS capability_name,
                    EXISTS (
                        SELECT 1 FROM robot_capabilities rc
                        WHERE rc.robot_id = r.id AND rc.capability_id = t.required_capability_id
                    ) AS has_capability
             FROM robots r CROSS JOIN tasks t
             LEFT JOIN capabilities c ON c.id = t.required_capability_id
             WHERE r.id = ? AND t.id = ?"
        );
        $stmt->execute([$robotId, $taskId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return ['Robot or task does not exist.'];
        }

        $reasons = [];
        $status  = RobotStatus::tryFrom((string) $row['status']);

        if ($status !== null && !$status->isBookable()) {
            $reasons[] = "Robot status is '{$status->value}'.";
        }
        if ((int) $row['battery_level'] < (int) $row['min_battery_level']) {
            $reasons[] = sprintf(
                'Battery %d%% is below the %d%% minimum for this task.',
                (int) $row['battery_level'],
                (int) $row['min_battery_level']
            );
        }
        if ($row['required_capability_id'] !== null && !self::pgBool($row['has_capability'])) {
            $reasons[] = "Robot lacks the required capability ({$row['capability_name']}).";
        }

        $duration = (int) $row['estimated_duration'];

        if ($duration > Schedule::MAX_BOOKING_MINUTES) {
            $reasons[] = sprintf(
                'Task runs %d minutes; a single booking may not exceed %d.',
                $duration,
                Schedule::MAX_BOOKING_MINUTES
            );
        }

        // Reported through the same breakdown the scheduler and the ping reply
        // use, so the refusal quotes the identical ledger the booking would.
        $duty = Schedule::dutyBreakdown(
            (int) $row['max_duty_minutes'],
            (int) $row['duty_minutes_used'],
            (int) $row['return_reserve_minutes'],
            (string) $row['duty_class'],
        );

        if ($duty['schedulable_remaining'] < $duration) {
            $reasons[] = sprintf(
                'Only %d of %d duty minutes are bookable (%d used, %d held back for the return trip to a charging dock); this task needs %d.',
                $duty['schedulable_remaining'],
                (int) $row['max_duty_minutes'],
                (int) $row['duty_minutes_used'],
                (int) $row['return_reserve_minutes'],
                $duration
            );
        }

        return $reasons;
    }

    /**
     * @param array{name: string, type: string, battery_level: int} $data validated by Http\Validator
     */
    public function create(array $data): BaseRobot
    {
        $stmt = $this->db->prepare(
            'INSERT INTO robots (name, type, battery_level)
             VALUES (:name, :type, :battery_level)
             RETURNING *'
        );
        $stmt->execute([
            'name'          => $data['name'],
            'type'          => $data['type'],
            'battery_level' => $data['battery_level'] ?? 100,
        ]);

        return RobotFactory::create($stmt->fetch());
    }

    public function updateStatus(int $id, RobotStatus $status): BaseRobot
    {
        $stmt = $this->db->prepare('UPDATE robots SET status = ? WHERE id = ? RETURNING *');
        $stmt->execute([$status->value, $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw NotFoundException::robot($id);
        }

        return RobotFactory::create($row);
    }

    // ---------------------------------------------------------- internals

    /**
     * @param array{sql: string, params: array<string, mixed>} $access
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $access, array $filters): array
    {
        $clauses = [$access['sql']];
        $params  = $access['params'];

        // Arena acts as a view filter layered on top of access, never as a
        // widening of it -- selecting an arena narrows, it cannot grant.
        if (isset($filters['arena_id'])) {
            $clauses[] = 'EXISTS (SELECT 1 FROM robot_arenas ra
                                  WHERE ra.robot_id = r.id AND ra.arena_id = :arena_id)';
            $params['arena_id'] = (int) $filters['arena_id'];
        }
        if (isset($filters['status'])) {
            $clauses[] = 'r.status = :status';
            $params['status'] = $filters['status'];
        }
        if (isset($filters['type'])) {
            $clauses[] = 'r.type = :type';
            $params['type'] = $filters['type'];
        }

        return [implode(' AND ', $clauses), $params];
    }

    /** @param array<string, mixed> $params */
    private function bind(string $sql, array $params): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt;
    }

    /** @param list<RobotStatus> $statuses */
    private static function statusList(array $statuses): string
    {
        // Enum-derived, never caller input -- safe to inline.
        return implode(', ', array_map(
            static fn (RobotStatus $s) => "'" . $s->value . "'",
            $statuses
        ));
    }

    private static function pgBool(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
    }
}
