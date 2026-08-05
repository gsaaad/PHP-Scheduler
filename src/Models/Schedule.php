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
                'SELECT id, status, COALESCE(battery_level, 0) AS battery_level
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

            $task = $this->findTask($taskId);
            $endTime = $startTime->modify('+' . (int) $task['estimated_duration'] . ' minutes');

            $this->assertHasBattery($robotId, $robot, $task);
            $this->assertHasCapability($robotId, $task);
            $this->assertNoOverlap($robotId, $startTime, $endTime);

            $stmt = $this->db->prepare(
                'INSERT INTO schedules (robot_id, task_id, start_time, end_time, status)
                 VALUES (?, ?, ?, ?, ?)
                 RETURNING id, robot_id, task_id, start_time, end_time, status, created_at'
            );
            $stmt->execute([
                $robotId,
                $taskId,
                $startTime->format(self::SQL_FORMAT),
                $endTime->format(self::SQL_FORMAT),
                ScheduleStatus::Scheduled->value,
            ]);
            $schedule = $stmt->fetch();

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
