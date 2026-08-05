<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that need a live PostgreSQL. Skips (rather than fails) when
 * DB_HOST is unset, so `composer test:unit` stays runnable on a bare machine.
 *
 * Fixtures are tracked and deleted in tearDown rather than wrapped in an outer
 * transaction: PDO has no nested transactions, and the code under test opens
 * its own -- which is precisely the behaviour these tests need to exercise.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $db;

    /** @var list<int> */
    private array $robotIds = [];
    /** @var list<int> */
    private array $taskIds = [];
    /** @var list<int> */
    private array $capabilityIds = [];
    /** @var list<int> */
    private array $departmentIds = [];
    /** @var list<int> */
    private array $arenaIds = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $roleIds = [];

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        if ($host === false || $host === '') {
            $this->markTestSkipped('DB_HOST is not set; skipping integration test.');
        }
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql extension is not loaded.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $host,
            getenv('DB_PORT') ?: '5432',
            getenv('DB_NAME') ?: 'robot_scheduler'
        );

        $this->db = new PDO($dsn, getenv('DB_USER') ?: 'user', getenv('DB_PASSWORD') ?: 'password', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }

        // Order matters: robots cascade to schedules/robot_capabilities; tasks
        // cascade to schedules; capabilities are referenced by tasks; users and
        // access_rules are referenced by departments.
        $this->deleteAll('robots', $this->robotIds);
        $this->deleteAll('tasks', $this->taskIds);
        $this->deleteAll('capabilities', $this->capabilityIds);
        $this->deleteAll('users', $this->userIds);
        $this->deleteAll('roles', $this->roleIds);
        $this->deleteAll('arenas', $this->arenaIds);
        $this->deleteAll('departments', $this->departmentIds);

        $this->robotIds = $this->taskIds = $this->capabilityIds = [];
        $this->departmentIds = $this->arenaIds = $this->userIds = $this->roleIds = [];
    }

    // ------------------------------------------------- access fixtures

    protected function insertDepartment(string $name = 'TestLab'): int
    {
        $stmt = $this->db->prepare('INSERT INTO departments (name, building_code) VALUES (?, ?) RETURNING id');
        $stmt->execute([$name . '-' . uniqid(), 'T1']);

        return $this->departmentIds[] = (int) $stmt->fetchColumn();
    }

    protected function insertArena(string $name = 'TestArena'): int
    {
        $stmt = $this->db->prepare('INSERT INTO arenas (name, type) VALUES (?, ?) RETURNING id');
        $stmt->execute([$name . '-' . uniqid(), 'Indoor']);

        return $this->arenaIds[] = (int) $stmt->fetchColumn();
    }

    protected function insertRole(
        string $name,
        bool $canSchedule = false,
        bool $canMaintain = false,
        bool $isAdmin = false,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO roles (name, can_schedule, can_maintain, is_admin) VALUES (?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([
            $name . '-' . uniqid(),
            $canSchedule ? 1 : 0,
            $canMaintain ? 1 : 0,
            $isAdmin ? 1 : 0,
        ]);

        return $this->roleIds[] = (int) $stmt->fetchColumn();
    }

    protected function insertUser(?int $departmentId, ?int $roleId = null, string $password = 'secret123'): int
    {
        $u    = 'u' . uniqid();
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, email, password_hash, department_id) VALUES (?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([$u, $u . '@example.test', password_hash($password, PASSWORD_DEFAULT), $departmentId]);
        $id = $this->userIds[] = (int) $stmt->fetchColumn();

        if ($roleId !== null) {
            $this->db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $roleId]);
        }

        return $id;
    }

    /**
     * @param list<array{0: string, 1: ?int, 2: ?string}> $criteria [kind, ref_id, ref_value]
     */
    protected function insertAccessRule(int $departmentId, string $name, array $criteria): int
    {
        $stmt = $this->db->prepare('INSERT INTO access_rules (department_id, name) VALUES (?, ?) RETURNING id');
        $stmt->execute([$departmentId, $name]);
        $ruleId = (int) $stmt->fetchColumn();

        $crit = $this->db->prepare(
            'INSERT INTO access_rule_criteria (rule_id, kind, ref_id, ref_value) VALUES (?, ?, ?, ?)'
        );
        foreach ($criteria as [$kind, $refId, $refValue]) {
            $crit->execute([$ruleId, $kind, $refId, $refValue]);
        }

        // Cascades from departments, which tearDown removes.
        return $ruleId;
    }

    protected function assignArena(int $robotId, int $arenaId): void
    {
        $this->db->prepare('INSERT INTO robot_arenas (robot_id, arena_id) VALUES (?, ?)')
            ->execute([$robotId, $arenaId]);
    }

    protected function assignDepartment(int $robotId, int $departmentId): void
    {
        $this->db->prepare('INSERT INTO robot_departments (robot_id, department_id) VALUES (?, ?)')
            ->execute([$robotId, $departmentId]);
    }

    protected function setBattery(int $robotId, int $level): void
    {
        $this->db->prepare('UPDATE robots SET battery_level = ? WHERE id = ?')->execute([$level, $robotId]);
    }

    /** @param list<int> $ids */
    private function deleteAll(string $table, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})")->execute($ids);
    }

    protected function insertRobot(string $status = 'idle', string $type = 'warehouse'): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO robots (name, type, status, battery_level, serial_number)
             VALUES (?, ?, ?, 100, ?) RETURNING id'
        );
        $stmt->execute(['TestBot', $type, $status, uniqid('SN-TEST-')]);

        return $this->robotIds[] = (int) $stmt->fetchColumn();
    }

    protected function insertCapability(string $name = 'cap'): int
    {
        $stmt = $this->db->prepare('INSERT INTO capabilities (name) VALUES (?) RETURNING id');
        $stmt->execute([$name . '-' . uniqid()]);

        return $this->capabilityIds[] = (int) $stmt->fetchColumn();
    }

    protected function insertTask(int $duration = 60, ?int $capabilityId = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO tasks (title, description, priority, estimated_duration, required_capability_id)
             VALUES (?, ?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute(['Test Task', 'fixture', 1, $duration, $capabilityId]);

        return $this->taskIds[] = (int) $stmt->fetchColumn();
    }

    protected function grantCapability(int $robotId, int $capabilityId): void
    {
        $stmt = $this->db->prepare('INSERT INTO robot_capabilities (robot_id, capability_id) VALUES (?, ?)');
        $stmt->execute([$robotId, $capabilityId]);
    }

    protected function robotStatus(int $robotId): string
    {
        $stmt = $this->db->prepare('SELECT status FROM robots WHERE id = ?');
        $stmt->execute([$robotId]);

        return (string) $stmt->fetchColumn();
    }

    protected function countSchedules(int $robotId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM schedules WHERE robot_id = ?');
        $stmt->execute([$robotId]);

        return (int) $stmt->fetchColumn();
    }

    /** A far-future window, so fixtures never collide with seeded data. */
    protected function futureTime(string $modify = '+1 day'): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('2030-01-01 09:00:00'))->modify($modify);
    }
}
