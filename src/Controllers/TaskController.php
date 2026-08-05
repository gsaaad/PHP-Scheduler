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
use App\Models\Task;
use Closure;
use DateTimeImmutable;
use PDO;

class TaskController
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

        JsonResponse::send([
            'data' => (new Task($this->conn()))->getAll($limit, $offset),
            'meta' => ['limit' => $limit, 'offset' => $offset],
        ]);
    }

    public function store(): void
    {
        if (!$this->auth->canSchedule && !$this->auth->isAdmin) {
            $this->audit()->denied($this->auth, 'task.create', 'task', null, [], Request::clientIp());
            throw ForbiddenException::missingPermission('can_schedule');
        }

        $data = Validator::task(Request::jsonBody());
        $task = (new Task($this->conn()))->create($data);

        $this->audit()->record(
            $this->auth,
            'task.create',
            'task',
            (int) $task['id'],
            ['title' => $task['title']],
            'success',
            Request::clientIp()
        );

        JsonResponse::send(['message' => 'Task created successfully', 'data' => $task], 201);
    }

    /**
     * Which robots can actually take this task.
     *
     * Answers in one call what previously required attempting a booking and
     * reading the 409: access scope, bookable status, required capability, and
     * battery headroom -- optionally excluding robots already booked across a
     * given window.
     */
    public function eligibleRobots(string $id): void
    {
        $taskId = (int) $id;
        $query  = Request::query();
        ['limit' => $limit, 'offset' => $offset] = Validator::pagination($query);

        $tasks = new Task($this->conn());
        $task  = $tasks->findOrFail($taskId);

        // Optional window: ?start_time=...  (duration comes from the task)
        $start = null;
        $end   = null;
        if (!empty($query['start_time'])) {
            try {
                $startAt = new DateTimeImmutable((string) $query['start_time']);
                $start   = $startAt->format('Y-m-d H:i:s');
                $end     = $startAt->modify('+' . (int) $task['estimated_duration'] . ' minutes')
                    ->format('Y-m-d H:i:s');
            } catch (\Exception) {
                throw new \App\Exceptions\ValidationException(
                    ['start_time' => 'Must be a valid datetime (e.g. 2030-03-01 09:00:00).']
                );
            }
        }

        $access = (new AccessPolicy($this->conn()))->robotFilter($this->auth);
        $robots = (new RobotRepository($this->conn()))
            ->eligibleForTask($taskId, $access, $limit, $offset, $start, $end);

        JsonResponse::send([
            'data' => $robots,
            'meta' => [
                'task'              => [
                    'id'                     => (int) $task['id'],
                    'title'                  => $task['title'],
                    'estimated_duration'     => (int) $task['estimated_duration'],
                    'min_battery_level'      => (int) $task['min_battery_level'],
                    'required_capability_id' => $task['required_capability_id'] === null
                        ? null : (int) $task['required_capability_id'],
                ],
                'window'            => $start === null ? null : ['start' => $start, 'end' => $end],
                'eligible_returned' => count($robots),
                'limit'             => $limit,
                'offset'            => $offset,
            ],
        ]);
    }

    /** Why a specific robot cannot take this task -- every failing gate, not just the first. */
    public function robotEligibility(string $id, string $robotId): void
    {
        $taskId  = (int) $id;
        $robotNo = (int) $robotId;

        (new Task($this->conn()))->findOrFail($taskId);

        $policy = new AccessPolicy($this->conn());
        if (!$policy->canAccessRobot($this->auth, $robotNo)) {
            throw ForbiddenException::robotOutOfScope($robotNo);
        }

        $reasons = (new RobotRepository($this->conn()))->ineligibilityReasons($robotNo, $taskId);

        JsonResponse::send([
            'data' => [
                'task_id'  => $taskId,
                'robot_id' => $robotNo,
                'eligible' => $reasons === [],
                'reasons'  => $reasons,
            ],
        ]);
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
