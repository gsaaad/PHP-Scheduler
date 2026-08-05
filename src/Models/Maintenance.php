<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\NotFoundException;
use PDO;
use Throwable;

/**
 * Maintenance and firmware lifecycle.
 *
 * Both tables were fully modelled in the original schema and never read by any
 * code. Opening maintenance moves the robot into 'maintenance' status (which
 * makes it unbookable), and closing it returns the robot to 'idle' -- so the
 * status field and the maintenance record can no longer drift apart.
 */
class Maintenance
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array{description: string, kind: string, cost: ?float} $data
     * @return array<string, mixed>
     */
    public function open(int $robotId, array $data, ?int $userId): array
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare('SELECT id FROM robots WHERE id = ? FOR UPDATE');
            $stmt->execute([$robotId]);
            if ($stmt->fetch() === false) {
                throw NotFoundException::robot($robotId);
            }

            $stmt = $this->db->prepare(
                'INSERT INTO maintenance_logs (robot_id, description, kind, cost, performed_by, status)
                 VALUES (?, ?, ?, ?, ?, ?)
                 RETURNING id, robot_id, description, kind, cost, status, performed_at'
            );
            $stmt->execute([
                $robotId,
                $data['description'],
                $data['kind'],
                $data['cost'],
                $userId,
                'open',
            ]);
            $log = $stmt->fetch();

            // Taking a robot out of service is the whole point of opening a job.
            $this->db->prepare('UPDATE robots SET status = ? WHERE id = ?')
                ->execute([RobotStatus::Maintenance->value, $robotId]);

            $this->db->commit();

            return $log;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    public function close(int $logId, ?int $userId): array
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'UPDATE maintenance_logs
                 SET status = ?, completed_at = CURRENT_TIMESTAMP, performed_by = COALESCE(performed_by, ?)
                 WHERE id = ? AND status = ?
                 RETURNING id, robot_id, description, kind, status, completed_at'
            );
            $stmt->execute(['completed', $userId, $logId, 'open']);
            $log = $stmt->fetch();

            if ($log === false) {
                $check = $this->db->prepare('SELECT status FROM maintenance_logs WHERE id = ?');
                $check->execute([$logId]);
                $existing = $check->fetch();

                if ($existing === false) {
                    throw new NotFoundException("Maintenance log {$logId} not found");
                }
                throw new \App\Exceptions\ConflictException(
                    "Maintenance log {$logId} is already {$existing['status']}."
                );
            }

            // Only lift the robot out of maintenance when nothing else is open.
            $stmt = $this->db->prepare(
                'SELECT 1 FROM maintenance_logs WHERE robot_id = ? AND status = ? LIMIT 1'
            );
            $stmt->execute([$log['robot_id'], 'open']);

            if ($stmt->fetch() === false) {
                $this->db->prepare('UPDATE robots SET status = ? WHERE id = ? AND status = ?')
                    ->execute([RobotStatus::Idle->value, $log['robot_id'], RobotStatus::Maintenance->value]);
            }

            $this->db->commit();

            return $log;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    public function forRobot(int $robotId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            'SELECT ml.*, u.username AS performed_by_username
             FROM maintenance_logs ml
             LEFT JOIN users u ON u.id = ml.performed_by
             WHERE ml.robot_id = :robot_id
             ORDER BY ml.performed_at DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('robot_id', $robotId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // -------------------------------------------------------- firmware

    /** @return list<array<string, mixed>> */
    public function firmwareReleases(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM firmware_updates ORDER BY release_date DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    public function createFirmwareRelease(string $version, ?string $description): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO firmware_updates (version, description) VALUES (?, ?) RETURNING *'
        );
        $stmt->execute([$version, $description]);

        return $stmt->fetch();
    }

    /**
     * Applies a release to a robot, recording the version it replaced.
     *
     * @return array<string, mixed>
     */
    public function applyFirmware(int $robotId, int $firmwareId, ?int $userId): array
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare('SELECT id, firmware_version FROM robots WHERE id = ? FOR UPDATE');
            $stmt->execute([$robotId]);
            $robot = $stmt->fetch();

            if ($robot === false) {
                throw NotFoundException::robot($robotId);
            }

            $stmt = $this->db->prepare('SELECT id, version FROM firmware_updates WHERE id = ?');
            $stmt->execute([$firmwareId]);
            $firmware = $stmt->fetch();

            if ($firmware === false) {
                throw new NotFoundException("Firmware release {$firmwareId} not found");
            }

            $stmt = $this->db->prepare(
                'INSERT INTO robot_firmware_updates (robot_id, firmware_update_id, previous_version, applied_by)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT (robot_id, firmware_update_id) DO NOTHING
                 RETURNING id, robot_id, firmware_update_id, previous_version, applied_at'
            );
            $stmt->execute([$robotId, $firmwareId, $robot['firmware_version'], $userId]);
            $applied = $stmt->fetch();

            if ($applied === false) {
                throw new \App\Exceptions\ConflictException(
                    "Robot {$robotId} already has firmware {$firmware['version']} applied."
                );
            }

            $this->db->prepare('UPDATE robots SET firmware_version = ? WHERE id = ?')
                ->execute([$firmware['version'], $robotId]);

            $this->db->commit();

            return $applied + ['version' => $firmware['version']];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
