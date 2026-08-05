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
use App\Models\RobotRepository;
use App\Models\RobotStatus;
use Closure;
use PDO;

class RobotController
{
    private ?PDO $connection = null;

    public function __construct(
        private readonly Closure $db,
        private readonly AuthContext $auth,
    ) {
    }

    public function index(): void
    {
        ['limit' => $limit, 'offset' => $offset] = Validator::pagination(Request::query());
        $filters = Validator::robotFilters(Request::query());

        $repo   = new RobotRepository($this->conn());
        $access = (new AccessPolicy($this->conn()))->robotFilter($this->auth);

        JsonResponse::send([
            'data' => $repo->getAll($limit, $offset, $access, $filters),
            'meta' => [
                'limit'   => $limit,
                'offset'  => $offset,
                // Scoped to what this caller may reach, not the whole fleet.
                'total'   => $repo->count($access, $filters),
                'filters' => $filters,
                'scope'   => $this->auth->isAdmin ? 'fleet' : 'department',
            ],
        ]);
    }

    public function show(string $id): void
    {
        $access = (new AccessPolicy($this->conn()))->robotFilter($this->auth);
        $robot  = (new RobotRepository($this->conn()))->find((int) $id, $access);

        // Out-of-scope and non-existent are both 403: distinguishing them would
        // let a caller enumerate robots beyond their reach.
        if ($robot === null) {
            throw ForbiddenException::robotOutOfScope((int) $id);
        }

        JsonResponse::send(['data' => $robot]);
    }

    public function store(): void
    {
        // Registering fleet hardware is an administrative act.
        if (!$this->auth->isAdmin) {
            $this->audit()->denied($this->auth, 'robot.create', 'robot', null, [], Request::clientIp());
            throw ForbiddenException::missingPermission('admin');
        }

        $data  = Validator::robot(Request::jsonBody());
        $robot = (new RobotRepository($this->conn()))->create($data);

        $this->audit()->record(
            $this->auth,
            'robot.create',
            'robot',
            $robot->getId(),
            ['name' => $robot->getName(), 'type' => $robot->getType()],
            'success',
            Request::clientIp()
        );

        JsonResponse::send(['message' => 'Robot created successfully', 'data' => $robot], 201);
    }

    public function updateStatus(string $id): void
    {
        $robotId = (int) $id;

        if (!$this->auth->canMaintain && !$this->auth->isAdmin) {
            $this->audit()->denied($this->auth, 'robot.status', 'robot', $robotId, [], Request::clientIp());
            throw ForbiddenException::missingPermission('can_maintain');
        }

        $policy = new AccessPolicy($this->conn());
        if (!$policy->canAccessRobot($this->auth, $robotId)) {
            $this->audit()->denied($this->auth, 'robot.status', 'robot', $robotId, ['reason' => 'out_of_scope'], Request::clientIp());
            throw ForbiddenException::robotOutOfScope($robotId);
        }

        $data   = Validator::robotStatus(Request::jsonBody());
        $status = RobotStatus::from($data['status']);
        $robot  = (new RobotRepository($this->conn()))->updateStatus($robotId, $status);

        $this->audit()->record(
            $this->auth,
            'robot.status',
            'robot',
            $robotId,
            ['status' => $status->value],
            'success',
            Request::clientIp()
        );

        JsonResponse::send(['message' => 'Status updated', 'data' => $robot]);
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
