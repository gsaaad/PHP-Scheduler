<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use DateTimeImmutable;
use PDO;
use Throwable;

class Schedule
{
    private const SQL_FORMAT = 'Y-m-d H:i:s';

    /** No single booking may exceed three hours. Mirrored by a CHECK constraint. */
    public const MAX_BOOKING_MINUTES = 180;

    /**
     * Once a robot's remaining duty budget falls to this or below, the leftover
     * is too small to be worth allocating, so completing its last booking sends
     * it to charge instead of leaving a stub of unusable time on the books.
     */
    public const DISPATCH_THRESHOLD_MINUTES = 30;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Books a robot for a task.
     *
     * Replaces the original implementation, which had four separate defects:
     * it ran the INSERT and the robot UPDATE outside a transaction, dereferenced
     * a `false` fetch when the robot did not exist, hardcoded end_time to
     * start + 1 hour, and gated on `status !== 'idle'` -- which both blocked
     * future bookings for a currently-busy robot and, because nothing ever reset
     * the flag, made every robot permanently unbookable after its first job.
     *
     * @return array<string, mixed> the created schedule row
     */
    public function scheduleTask(int $robotId, int $taskId, DateTimeImmutable $startTime): array
    {
        $this->db->beginTransaction();

        try {
            // FOR UPDATE serialises concurrent bookings for the same robot, so
            // the overlap check below cannot be raced by a parallel request.
            $stmt = $this->db->prepare(
                'SELECT id, status, COALESCE(battery_level, 0) AS battery_level,
                        max_duty_minutes, duty_minutes_used, return_reserve_minutes, duty_class
                 FROM robots WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$robotId]);
            $robot = $stmt->fetch();

            if ($robot === false) {
                throw NotFoundException::robot($robotId);
            }

            $status = RobotStatus::tryFrom((string) $robot['status']);
            if ($status !== null && !$status->isBookable()) {
                throw new ConflictException("Robot {$robotId} is unavailable (status: {$status->value}).");
            }

            $task     = $this->findTask($taskId);
            $duration = (int) $task['estimated_duration'];
            $endTime  = $startTime->modify("+{$duration} minutes");

            $this->assertWithinBookingCap($duration, $task);
            $this->assertHasDutyBudget($robotId, $robot, $duration);
            $this->assertHasBattery($robotId, $robot, $task);
            $this->assertHasCapability($robotId, $task);
            $this->assertNoOverlap($robotId, $startTime, $endTime);

            $stmt = $this->db->prepare(
                'INSERT INTO schedules (robot_id, task_id, start_time, end_time, status, duty_minutes)
                 VALUES (?, ?, ?, ?, ?, ?)
                 RETURNING id, robot_id, task_id, start_time, end_time, status, duty_minutes, created_at'
            );
            $stmt->execute([
                $robotId,
                $taskId,
                $startTime->format(self::SQL_FORMAT),
                $endTime->format(self::SQL_FORMAT),
                ScheduleStatus::Scheduled->value,
                $duration,
            ]);
            $schedule = $stmt->fetch();

            // Budget is committed at booking time, not at completion: that is
            // what makes the remainder visible to the next department before
            // they try to book it.
            $this->db->prepare('UPDATE robots SET duty_minutes_used = duty_minutes_used + ? WHERE id = ?')
                ->execute([$duration, $robotId]);

            // What the next department can actually book, with the return trip
            // already carved out.
            $schedule['duty'] = self::dutyBreakdown(
                (int) $robot['max_duty_minutes'],
                (int) $robot['duty_minutes_used'] + $duration,
                (int) $robot['return_reserve_minutes'],
                (string) $robot['duty_class'],
            );
            $schedule['duty_minutes_remaining'] = $schedule['duty']['schedulable_remaining'];

            // Only flip the robot to busy if the window is live right now. A
            // booking for next week must not mark it busy today.
            $now = new DateTimeImmutable();
            if ($startTime <= $now && $now < $endTime) {
                $this->setRobotStatus($robotId, RobotStatus::Busy);
            }

            $this->db->commit();

            return $schedule;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Closes out a booking and releases the robot. This is the half of the
     * lifecycle the original code never had -- without it, robots accumulated
     * as 'busy' forever.
     *
     * @return array<string, mixed> the updated schedule row
     */
    public function complete(int $scheduleId): array
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'UPDATE schedules SET status = ?
                 WHERE id = ? AND status = ?
                 RETURNING id, robot_id, task_id, start_time, end_time, status'
            );
            $stmt->execute([
                ScheduleStatus::Completed->value,
                $scheduleId,
                ScheduleStatus::Scheduled->value,
            ]);
            $schedule = $stmt->fetch();

            if ($schedule === false) {
                // Distinguish "no such schedule" from "already closed"
                $check = $this->db->prepare('SELECT status FROM schedules WHERE id = ?');
                $check->execute([$scheduleId]);
                $existing = $check->fetch();

                if ($existing === false) {
                    throw NotFoundException::schedule($scheduleId);
                }
                throw new ConflictException(
                    "Schedule {$scheduleId} is already {$existing['status']} and cannot be completed."
                );
            }

            $robotId = (int) $schedule['robot_id'];

            // Release the robot only when nothing else is actively running, and
            // only from 'busy' -- never clobber maintenance/error/charging.
            if (!$this->hasActiveSchedule($robotId)) {
                $stmt = $this->db->prepare('UPDATE robots SET status = ? WHERE id = ? AND status = ?');
                $stmt->execute([RobotStatus::Idle->value, $robotId, RobotStatus::Busy->value]);

                // Spent robots take themselves off the board rather than waiting
                // for someone to notice the remainder is unusable.
                $schedule['dispatched_to_charge'] = $this->dispatchToChargingIfSpent($robotId);
            }

            $this->db->commit();

            return $schedule;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Bookings overlapping a window, with the robot and task joined in.
     *
     * Drives both the month calendar and the Gantt view: they differ only in
     * how they arrange the same rows, so they share one query rather than
     * drifting apart.
     *
     * @param array{sql: string, params: array<string, mixed>} $access AccessPolicy::robotFilter('r')
     * @return list<array<string, mixed>>
     */
    public function inWindow(
        string $from,
        string $to,
        array $access = ['sql' => 'TRUE', 'params' => []],
        ?int $robotId = null,
        int $limit = 1000,
    ): array {
        $sql = "SELECT s.id, s.robot_id, s.task_id, s.start_time, s.end_time, s.status,
                       s.duty_minutes,
                       r.name AS robot_name, r.type AS robot_type, r.status AS robot_status,
                       r.battery_level, r.duty_class,
                       r.max_duty_minutes, r.duty_minutes_used, r.return_reserve_minutes,
                       t.title AS task_title, t.priority AS task_priority
                FROM schedules s
                JOIN robots r ON r.id = s.robot_id
                JOIN tasks  t ON t.id = s.task_id
                WHERE {$access['sql']}
                  AND s.status <> 'cancelled'
                  -- overlap, not containment: a booking straddling the window
                  -- edge still belongs on the calendar
                  AND s.start_time < CAST(:to AS timestamp)
                  AND s.end_time   > CAST(:from AS timestamp)";

        $params = $access['params'] + ['from' => $from, 'to' => $to];

        if ($robotId !== null) {
            $sql .= ' AND s.robot_id = :robot_id';
            $params['robot_id'] = $robotId;
        }

        $sql .= ' ORDER BY s.start_time ASC, s.id ASC LIMIT :limit';
        $params['limit'] = $limit;

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Robots the caller can reach, with duty state, for the Gantt row axis.
     * Includes robots with no bookings -- an empty row is information.
     *
     * @param array{sql: string, params: array<string, mixed>} $access
     * @return list<array<string, mixed>>
     */
    public function robotsForGantt(array $access, int $limit = 60, int $offset = 0): array
    {
        $sql = "SELECT r.id, r.name, r.type, r.status, r.battery_level, r.duty_class,
                       r.max_duty_minutes, r.duty_minutes_used, r.return_reserve_minutes
                FROM robots r
                WHERE {$access['sql']}
                ORDER BY r.name ASC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($access['params'] + ['limit' => $limit, 'offset' => $offset] as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @param array{sql: string, params: array<string, mixed>} $access AccessPolicy::robotFilter('r')
     * @return list<array<string, mixed>>
     */
    public function getFullSchedule(
        int $limit = 50,
        int $offset = 0,
        ?int $robotId = null,
        array $access = ['sql' => 'TRUE', 'params' => []],
    ): array {
        // The access filter joins through to robots, so a caller never sees a
        // booking for a robot outside their scope.
        $sql = "SELECT s.*, r.name AS robot_name, t.title AS task_title, t.priority AS task_priority
                FROM schedules s
                JOIN robots r ON s.robot_id = r.id
                JOIN tasks  t ON s.task_id  = t.id
                WHERE {$access['sql']}";

        $params = $access['params'];

        if ($robotId !== null) {
            $sql .= ' AND s.robot_id = :robot_id';
            $params['robot_id'] = $robotId;
        }

        $sql .= ' ORDER BY s.start_time ASC LIMIT :limit OFFSET :offset';
        $params += ['limit' => $limit, 'offset' => $offset];

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Sends a robot to charge when its duty budget is spent.
     *
     * Runs inside the caller's transaction. Returns the dispatch detail so the
     * response can tell the operator where the robot went, or null when it
     * still has usable time.
     *
     * @return array{arena_id: ?int, arena_name: ?string, distance_m: ?float, duty_minutes_left: int}|null
     */
    private function dispatchToChargingIfSpent(int $robotId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT max_duty_minutes, duty_minutes_used, return_reserve_minutes, duty_class, status
             FROM robots WHERE id = ?'
        );
        $stmt->execute([$robotId]);
        $robot = $stmt->fetch();

        if ($robot === false) {
            return null;
        }

        $duty = self::dutyBreakdown(
            (int) $robot['max_duty_minutes'],
            (int) $robot['duty_minutes_used'],
            (int) $robot['return_reserve_minutes'],
            (string) $robot['duty_class'],
        );

        // Once the bookable remainder is too small to be worth allocating, the
        // robot spends its reserve getting home rather than idling with a stub
        // of unusable time on the books.
        $remaining = $duty['schedulable_remaining'];
        if ($remaining > self::DISPATCH_THRESHOLD_MINUTES) {
            return null;
        }

        $station = (new Geography($this->db))->nearestChargingStation($robotId);

        $this->db->prepare(
            'INSERT INTO charge_sessions (robot_id, arena_id, reason, duty_minutes_at_start)
             VALUES (?, ?, ?, ?)'
        )->execute([$robotId, $station['id'] ?? null, 'duty_exhausted', (int) $robot['duty_minutes_used']]);

        $this->db->prepare(
            'UPDATE robots SET status = ?, charging_arena_id = ? WHERE id = ?'
        )->execute([RobotStatus::Charging->value, $station['id'] ?? null, $robotId]);

        return [
            'arena_id'          => $station['id'] ?? null,
            'arena_name'        => $station['name'] ?? null,
            'distance_m'        => $station['distance_m'] ?? null,
            'duty_minutes_left' => max(0, $remaining),
        ];
    }

    /**
     * Ends a charge cycle: the duty budget resets and the robot returns to
     * service, positioned at the station it charged in.
     *
     * @return array<string, mixed>
     */
    public function completeCharge(int $robotId): array
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'SELECT id, status, charging_arena_id, duty_minutes_used FROM robots WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$robotId]);
            $robot = $stmt->fetch();

            if ($robot === false) {
                throw NotFoundException::robot($robotId);
            }
            if ($robot['status'] !== RobotStatus::Charging->value) {
                throw new ConflictException("Robot {$robotId} is not charging (status: {$robot['status']}).");
            }

            $this->db->prepare(
                'UPDATE charge_sessions SET completed_at = CURRENT_TIMESTAMP
                 WHERE robot_id = ? AND completed_at IS NULL'
            )->execute([$robotId]);

            // Move the robot to the station it charged at, so its reported
            // position matches where it physically is. Resolved explicitly
            // rather than joined: with no station recorded, a COALESCE join
            // condition would match every arena row.
            $station = null;
            if ($robot['charging_arena_id'] !== null) {
                $stmt = $this->db->prepare('SELECT latitude, longitude FROM arenas WHERE id = ?');
                $stmt->execute([$robot['charging_arena_id']]);
                $found = $stmt->fetch();
                $station = $found === false ? null : $found;
            }

            $this->db->prepare(
                'UPDATE robots SET
                    status = ?,
                    duty_minutes_used = 0,
                    duty_reset_at = CURRENT_TIMESTAMP,
                    battery_level = 100,
                    current_location_lat = COALESCE(?, current_location_lat),
                    current_location_lng = COALESCE(?, current_location_lng),
                    charging_arena_id = NULL
                 WHERE id = ?'
            )->execute([
                RobotStatus::Idle->value,
                $station['latitude']  ?? null,
                $station['longitude'] ?? null,
                $robotId,
            ]);

            $stmt = $this->db->prepare(
                'SELECT id, name, status, battery_level, duty_minutes_used, max_duty_minutes
                 FROM robots WHERE id = ?'
            );
            $stmt->execute([$robotId]);
            $updated = $stmt->fetch();

            $this->db->commit();

            return $updated;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** The robot a booking belongs to, for access checks before mutating it. */
    public function robotIdFor(int $scheduleId): ?int
    {
        $stmt = $this->db->prepare('SELECT robot_id FROM schedules WHERE id = ?');
        $stmt->execute([$scheduleId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return array<string, mixed> */
    private function findTask(int $taskId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, title, estimated_duration, required_capability_id, min_battery_level
             FROM tasks WHERE id = ?'
        );
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if ($task === false) {
            throw NotFoundException::task($taskId);
        }

        return $task;
    }

    /** @param array<string, mixed> $task */
    private function assertWithinBookingCap(int $duration, array $task): void
    {
        if ($duration > self::MAX_BOOKING_MINUTES) {
            throw new ConflictException(sprintf(
                'Task "%s" runs %d minutes; a single booking is capped at %d minutes (3 hours).',
                $task['title'],
                $duration,
                self::MAX_BOOKING_MINUTES
            ));
        }
    }

    /**
     * Endurance is shared across departments, and part of it is not for sale:
     * the robot has to drive itself back to a dock. A 4.5-hour platform booked
     * for 3 hours leaves the next department 1 hour, not 1.5 -- the last half
     * hour is the trip home, and the message says so explicitly rather than
     * letting a department plan around time that does not exist.
     *
     * @param array<string, mixed> $robot
     */
    private function assertHasDutyBudget(int $robotId, array $robot, int $duration): void
    {
        $duty = self::dutyBreakdown(
            (int) $robot['max_duty_minutes'],
            (int) $robot['duty_minutes_used'],
            (int) $robot['return_reserve_minutes'],
            (string) $robot['duty_class'],
        );

        if ($duration > $duty['schedulable_remaining']) {
            throw new ConflictException(sprintf(
                'Robot %d has %s bookable (%s endurance, %s used, %s held back for the return '
                . 'trip to a charging dock); this task needs %s.',
                $robotId,
                self::humanMinutes($duty['schedulable_remaining']),
                self::humanMinutes($duty['endurance']),
                self::humanMinutes($duty['used']),
                self::humanMinutes($duty['return_reserve']),
                self::humanMinutes($duration)
            ));
        }
    }

    /**
     * The single place duty arithmetic lives, so the scheduler, the ping reply
     * and the dashboard cannot disagree about how much time is left.
     *
     * @return array{duty_class: string, endurance: int, used: int, return_reserve: int,
     *               schedulable_total: int, schedulable_remaining: int, note: string}
     */
    public static function dutyBreakdown(int $endurance, int $used, int $reserve, string $class = 'standard'): array
    {
        $schedulableTotal = max(0, $endurance - $reserve);

        return [
            'duty_class'            => $class,
            'endurance'             => $endurance,
            'used'                  => $used,
            'return_reserve'        => $reserve,
            'schedulable_total'     => $schedulableTotal,
            'schedulable_remaining' => max(0, $schedulableTotal - $used),
            'note'                  => sprintf(
                '%s of this %s platform\'s %s endurance is reserved for its return to a charging dock.',
                self::humanMinutes($reserve),
                $class,
                self::humanMinutes($endurance)
            ),
        ];
    }

    private static function humanMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} min";
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m === 0 ? "{$h}h" : "{$h}h {$m}m";
    }

    /**
     * Battery was stored from the beginning and never consulted. The threshold
     * is per-task, so a 3-hour patrol demands more headroom than a 15-minute
     * data sync.
     *
     * @param array<string, mixed> $robot
     * @param array<string, mixed> $task
     */
    private function assertHasBattery(int $robotId, array $robot, array $task): void
    {
        $battery = (int) $robot['battery_level'];
        $minimum = (int) $task['min_battery_level'];

        if ($battery < $minimum) {
            throw new ConflictException(sprintf(
                'Robot %d has %d%% battery, below the %d%% minimum for task "%s".',
                $robotId,
                $battery,
                $minimum,
                $task['title']
            ));
        }
    }

    /**
     * The schema models robot_capabilities and the seeder populates it, but
     * nothing read it before -- a surgical robot could be booked for a task
     * requiring hazmat handling.
     *
     * @param array<string, mixed> $task
     */
    private function assertHasCapability(int $robotId, array $task): void
    {
        $capabilityId = $task['required_capability_id'] ?? null;
        if ($capabilityId === null) {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT c.name FROM robot_capabilities rc
             JOIN capabilities c ON c.id = rc.capability_id
             WHERE rc.robot_id = ? AND rc.capability_id = ?'
        );
        $stmt->execute([$robotId, $capabilityId]);

        if ($stmt->fetch() === false) {
            $name = $this->capabilityName((int) $capabilityId);
            throw new ConflictException(
                "Robot {$robotId} lacks the capability required for task \"{$task['title']}\" ({$name})."
            );
        }
    }

    private function assertNoOverlap(int $robotId, DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        // Postgres OVERLAPS treats intervals as half-open, so a booking that
        // starts exactly when another ends is not a conflict. The explicit CASTs
        // are required because emulated prepares are off and the placeholders
        // would otherwise have no inferable type.
        $stmt = $this->db->prepare(
            'SELECT id, start_time, end_time FROM schedules
             WHERE robot_id = ?
               AND status <> ?
               AND (start_time, end_time) OVERLAPS (CAST(? AS timestamp), CAST(? AS timestamp))
             LIMIT 1'
        );
        $stmt->execute([
            $robotId,
            ScheduleStatus::Cancelled->value,
            $start->format(self::SQL_FORMAT),
            $end->format(self::SQL_FORMAT),
        ]);

        $clash = $stmt->fetch();
        if ($clash !== false) {
            throw new ConflictException(sprintf(
                'Robot %d is already booked from %s to %s (schedule %d).',
                $robotId,
                $clash['start_time'],
                $clash['end_time'],
                $clash['id']
            ));
        }
    }

    private function hasActiveSchedule(int $robotId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM schedules
             WHERE robot_id = ? AND status = ?
               AND CURRENT_TIMESTAMP >= start_time AND CURRENT_TIMESTAMP < end_time
             LIMIT 1'
        );
        $stmt->execute([$robotId, ScheduleStatus::Scheduled->value]);

        return $stmt->fetch() !== false;
    }

    private function setRobotStatus(int $robotId, RobotStatus $status): void
    {
        $stmt = $this->db->prepare('UPDATE robots SET status = ? WHERE id = ?');
        $stmt->execute([$status->value, $robotId]);
    }

    private function capabilityName(int $capabilityId): string
    {
        $stmt = $this->db->prepare('SELECT name FROM capabilities WHERE id = ?');
        $stmt->execute([$capabilityId]);
        $name = $stmt->fetchColumn();

        return $name === false ? "capability #{$capabilityId}" : (string) $name;
    }
}
