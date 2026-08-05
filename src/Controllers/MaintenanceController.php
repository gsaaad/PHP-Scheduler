<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Audit\AuditLogger;
use App\Auth\AccessPolicy;
use App\Auth\AuthContext;
use App\Exceptions\ForbiddenException;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Validator;
use App\Models\Maintenance;
use Closure;
use PDO;

class MaintenanceController
{
    private ?PDO $connection = null;

    public function __construct(
        private readonly Closure $db,
        private readonly AuthContext $auth,
    ) {
    }

    public function index(string $robotId): void
    {
        $this->assertAccess((int) $robotId);
        ['limit' => $limit, 'offset' => $offset] = Validator::pagination(Request::query());

        JsonResponse::send([
            'data' => (new Maintenance($this->conn()))->forRobot((int) $robotId, $limit, $offset),
            'meta' => ['robot_id' => (int) $robotId, 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    public function open(string $robotId): void
    {
        $id = (int) $robotId;
        $this->assertMaintainer('maintenance.open', $id);
        $this->assertAccess($id);

        $data = Validator::maintenance(Request::jsonBody());
        $log  = (new Maintenance($this->conn()))->open($id, $data, $this->auth->userId);

        $this->audit()->record(
            $this->auth,
            'maintenance.open',
            'maintenance_log',
            (int) $log['id'],
            ['robot_id' => $id, 'kind' => $data['kind']],
            'success',
            Request::clientIp()
        );

        JsonResponse::send([
            'message' => 'Maintenance opened; robot taken out of service.',
            'data'    => $log,
        ], 201);
    }

    public function close(string $logId): void
    {
        $this->assertMaintainer('maintenance.close', (int) $logId);

        $log = (new Maintenance($this->conn()))->close((int) $logId, $this->auth->userId);

        $this->audit()->record(
            $this->auth,
            'maintenance.close',
            'maintenance_log',
            (int) $logId,
            ['robot_id' => $log['robot_id']],
            'success',
            Request::clientIp()
        );

        JsonResponse::send(['message' => 'Maintenance closed.', 'data' => $log]);
    }

    // -------------------------------------------------------- firmware

    public function firmwareIndex(): void
    {
        ['limit' => $limit, 'offset' => $offset] = Validator::pagination(Request::query());

        JsonResponse::send([
            'data' => (new Maintenance($this->conn()))->firmwareReleases($limit, $offset),
            'meta' => ['limit' => $limit, 'offset' => $offset],
        ]);
    }

    public function firmwareStore(): void
    {
        if (!$this->auth->isAdmin) {
            $this->audit()->denied($this->auth, 'firmware.create', 'firmware_update', null, [], Request::clientIp());
            throw ForbiddenException::missingPermission('admin');
        }

        $data    = Validator::firmware(Request::jsonBody());
        $release = (new Maintenance($this->conn()))
            ->createFirmwareRelease($data['version'], $data['description']);

        $this->audit()->record(
            $this->auth,
            'firmware.create',
            'firmware_update',
            (int) $release['id'],
            ['version' => $data['version']],
            'success',
            Request::clientIp()
        );

        JsonResponse::send(['message' => 'Firmware release recorded', 'data' => $release], 201);
    }

    public function firmwareApply(string $robotId): void
    {
        $id = (int) $robotId;
        $this->assertMaintainer('firmware.apply', $id);
        $this->assertAccess($id);

        $body = Request::jsonBody();
        if (!is_array($body) || !isset($body['firmware_update_id']) || !ctype_digit((string) $body['firmware_update_id'])) {
            throw new \App\Exceptions\ValidationException(
                ['firmware_update_id' => 'A positive integer firmware_update_id is required.']
            );
        }

        $applied = (new Maintenance($this->conn()))
            ->applyFirmware($id, (int) $body['firmware_update_id'], $this->auth->userId);

        $this->audit()->record(
            $this->auth,
            'firmware.apply',
            'robot',
            $id,
            [
                'firmware_update_id' => (int) $body['firmware_update_id'],
                'previous_version'   => $applied['previous_version'],
                'version'            => $applied['version'],
            ],
            'success',
            Request::clientIp()
        );

        JsonResponse::send(['message' => 'Firmware applied', 'data' => $applied]);
    }

    // -------------------------------------------------------- internals

    private function assertMaintainer(string $action, ?int $entityId): void
    {
        if (!$this->auth->canMaintain && !$this->auth->isAdmin) {
            $this->audit()->denied($this->auth, $action, 'robot', $entityId, [], Request::clientIp());
            throw ForbiddenException::missingPermission('can_maintain');
        }
    }

    private function assertAccess(int $robotId): void
    {
        (new AccessPolicy($this->conn()))->assertCanAccessRobot($this->auth, $robotId);
    }

    private function conn(): PDO
    {
        return $this->connection ??= ($this->db)();
    }

    private function audit(): AuditLogger
    {
        return new AuditLogger($this->conn());
    }
}
