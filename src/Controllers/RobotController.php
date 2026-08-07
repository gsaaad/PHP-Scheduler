<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Audit\AuditLogger;
use App\Auth\AccessPolicy;
use App\Auth\AuthContext;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Validator;
use App\Models\RobotMedia;
use App\Models\RobotPing;
use App\Models\RobotRepository;
use App\Models\RobotStatus;
use App\Models\Schedule;
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

    /**
     * Ask a robot where it is and what it is doing.
     *
     * Scope-gated like every other robot read: you cannot ping hardware outside
     * your access rules. The reply is worded from a set matched to the robot's
     * status, but the telemetry underneath it is real.
     */
    public function ping(string $id): void
    {
        $robotId = (int) $id;
        (new AccessPolicy($this->conn()))->assertCanAccessRobot($this->auth, $robotId);

        JsonResponse::send(['data' => (new RobotPing($this->conn()))->ping($robotId)]);
    }

    /** Ends a charge cycle: duty budget resets and the robot returns to service. */
    public function completeCharge(string $id): void
    {
        $robotId = (int) $id;

        if (!$this->auth->canMaintain && !$this->auth->isAdmin) {
            $this->audit()->denied($this->auth, 'robot.charge.complete', 'robot', $robotId, [], Request::clientIp());
            throw ForbiddenException::missingPermission('can_maintain');
        }
        (new AccessPolicy($this->conn()))->assertCanAccessRobot($this->auth, $robotId);

        $robot = (new Schedule($this->conn()))->completeCharge($robotId);

        $this->audit()->record(
            $this->auth,
            'robot.charge.complete',
            'robot',
            $robotId,
            ['duty_reset' => true],
            'success',
            Request::clientIp()
        );

        JsonResponse::send(['message' => 'Charge complete; duty budget reset.', 'data' => $robot]);
    }

    /**
     * Upload a robot's still image or hover animation.
     *
     * Admin-only, and the file lands outside the web root. Slot comes from the
     * route, not the request body.
     */
    public function uploadMedia(string $id, string $slot): void
    {
        $robotId = (int) $id;

        if (!$this->auth->isAdmin) {
            $this->audit()->denied($this->auth, 'robot.media.upload', 'robot', $robotId, ['slot' => $slot], Request::clientIp());
            throw ForbiddenException::missingPermission('admin');
        }
        if (!in_array($slot, [RobotMedia::SLOT_IMAGE, RobotMedia::SLOT_HOVER], true)) {
            throw new ValidationException(['slot' => 'Slot must be "image" or "hover".']);
        }
        (new AccessPolicy($this->conn()))->assertCanAccessRobot($this->auth, $robotId);

        $file   = $_FILES['file'] ?? [];
        $stored = (new RobotMedia($this->conn(), RobotMedia::defaultStoragePath()))
            ->store($robotId, $slot, $file);

        $this->audit()->record(
            $this->auth,
            'robot.media.upload',
            'robot',
            $robotId,
            ['slot' => $slot, 'mime' => $stored['mime'], 'bytes' => $stored['bytes']],
            'success',
            Request::clientIp()
        );

        JsonResponse::send([
            'message' => 'Media stored.',
            'data'    => [
                'robot_id' => $robotId,
                'slot'     => $slot,
                'mime'     => $stored['mime'],
                'bytes'    => $stored['bytes'],
                'url'      => "/api/robots/{$robotId}/media/{$slot}",
            ],
        ], 201);
    }

    /**
     * Serve stored media. Scope-gated like any other robot read, so images of
     * another lab's hardware are not readable by URL guessing.
     */
    public function media(string $id, string $slot): void
    {
        $robotId = (int) $id;
        (new AccessPolicy($this->conn()))->assertCanAccessRobot($this->auth, $robotId);

        $found = (new RobotMedia($this->conn(), RobotMedia::defaultStoragePath()))->read($robotId, $slot);

        if ($found === null) {
            throw new NotFoundException("No {$slot} stored for robot {$robotId}");
        }

        header('Content-Type: ' . $found['mime']);
        header('Content-Length: ' . filesize($found['path']));
        // nosniff matters here: the bytes are user-supplied.
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');
        readfile($found['path']);
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
