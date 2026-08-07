<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Audit\AuditLogger;
use App\Auth\AccessPolicy;
use App\Auth\AuthContext;
use App\Exceptions\ForbiddenException;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Validator;
use App\Models\Schedule;
use Closure;
use DateTimeImmutable;
use PDO;
use Throwable;

class ScheduleController
{
    private ?PDO $connection = null;

    public function __construct(
        private readonly Closure $db,
        private readonly AuthContext $auth,
    ) {
    }

    public function index(): void
    {
        $query = Request::query();
        ['limit' => $limit, 'offset' => $offset] = Validator::pagination($query);

        $robotId = isset($query['robot_id']) && ctype_digit((string) $query['robot_id'])
            ? (int) $query['robot_id']
            : null;

        $access = (new AccessPolicy($this->conn()))->robotFilter($this->auth, 'r');

        JsonResponse::send([
            'data' => (new Schedule($this->conn()))->getFullSchedule($limit, $offset, $robotId, $access),
            'meta' => ['limit' => $limit, 'offset' => $offset, 'robot_id' => $robotId],
        ]);
    }

    /**
     * Bookings overlapping a window, for the month calendar and the Gantt view.
     *
     * ?from= and ?to= are dates or datetimes; ?view=gantt additionally returns
     * the robot rows so the chart can show robots that have nothing booked.
     */
    public function window(): void
    {
        $query = Request::query();

        try {
            $from = new DateTimeImmutable((string) ($query['from'] ?? 'today'));
            $to   = new DateTimeImmutable((string) ($query['to'] ?? '+7 days'));
        } catch (\Exception) {
            throw new ValidationException(['from' => 'from and to must be valid dates.']);
        }

        if ($to <= $from) {
            throw new ValidationException(['to' => 'to must be after from.']);
        }
        // A calendar never needs more than a quarter at once, and the cap stops
        // a crafted range from scanning the whole table.
        if ($from->diff($to)->days > 92) {
            throw new ValidationException(['to' => 'Range is limited to 92 days.']);
        }

        $robotId = isset($query['robot_id']) && ctype_digit((string) $query['robot_id'])
            ? (int) $query['robot_id']
            : null;

        $access    = (new AccessPolicy($this->conn()))->robotFilter($this->auth, 'r');
        $schedules = new Schedule($this->conn());

        $payload = [
            'data' => $schedules->inWindow(
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
                $access,
                $robotId
            ),
            'meta' => [
                'from'                => $from->format('Y-m-d H:i:s'),
                'to'                  => $to->format('Y-m-d H:i:s'),
                'max_booking_minutes' => Schedule::MAX_BOOKING_MINUTES,
            ],
        ];

        if (($query['view'] ?? '') === 'gantt') {
            ['limit' => $limit, 'offset' => $offset] = Validator::pagination($query, 40, 100);
            $payload['robots']         = $schedules->robotsForGantt($access, $limit, $offset);
            $payload['meta']['limit']  = $limit;
            $payload['meta']['offset'] = $offset;
        }

        JsonResponse::send($payload);
    }

    public function store(): void
    {
        if (!$this->auth->canSchedule && !$this->auth->isAdmin) {
            $this->audit()->denied($this->auth, 'schedule.create', 'schedule', null, [], Request::clientIp());
            throw ForbiddenException::missingPermission('can_schedule');
        }

        $data = Validator::schedule(Request::jsonBody());

        // Access is checked before the booking transaction opens, so an
        // out-of-scope attempt never touches the schedules table.
        $policy = new AccessPolicy($this->conn());
        if (!$policy->canAccessRobot($this->auth, $data['robot_id'])) {
            $this->audit()->denied(
                $this->auth,
                'schedule.create',
                'robot',
                $data['robot_id'],
                ['reason' => 'out_of_scope', 'task_id' => $data['task_id']],
                Request::clientIp()
            );
            throw ForbiddenException::robotOutOfScope($data['robot_id']);
        }

        try {
            $schedule = (new Schedule($this->conn()))->scheduleTask(
                $data['robot_id'],
                $data['task_id'],
                $data['start_time']
            );
        } catch (HttpException $e) {
            // Refusals (409 overlap / capability / battery, 404 missing) are
            // exactly what an auditor wants recorded -- and the business
            // transaction has already rolled back by the time we get here.
            $this->audit()->record(
                $this->auth,
                'schedule.create',
                'robot',
                $data['robot_id'],
                ['task_id' => $data['task_id'], 'reason' => $e->getMessage()],
                'rejected',
                Request::clientIp()
            );
            throw $e;
        } catch (Throwable $e) {
            $this->audit()->record(
                $this->auth,
                'schedule.create',
                'robot',
                $data['robot_id'],
                ['task_id' => $data['task_id']],
                'error',
                Request::clientIp()
            );
            throw $e;
        }

        $this->audit()->record(
            $this->auth,
            'schedule.create',
            'schedule',
            (int) $schedule['id'],
            [
                'robot_id'   => $data['robot_id'],
                'task_id'    => $data['task_id'],
                'start_time' => $schedule['start_time'],
                'end_time'   => $schedule['end_time'],
            ],
            'success',
            Request::clientIp()
        );

        JsonResponse::send(['message' => 'Task scheduled successfully', 'data' => $schedule], 201);
    }

    public function complete(string $id): void
    {
        if (!$this->auth->canSchedule && !$this->auth->isAdmin) {
            throw ForbiddenException::missingPermission('can_schedule');
        }

        $schedules = new Schedule($this->conn());
        $robotId   = $schedules->robotIdFor((int) $id);

        if ($robotId !== null && !(new AccessPolicy($this->conn()))->canAccessRobot($this->auth, $robotId)) {
            $this->audit()->denied($this->auth, 'schedule.complete', 'schedule', (int) $id, ['reason' => 'out_of_scope'], Request::clientIp());
            throw ForbiddenException::robotOutOfScope($robotId);
        }

        $schedule = $schedules->complete((int) $id);

        $this->audit()->record(
            $this->auth,
            'schedule.complete',
            'schedule',
            (int) $id,
            ['robot_id' => $schedule['robot_id']],
            'success',
            Request::clientIp()
        );

        JsonResponse::send(['message' => 'Schedule completed', 'data' => $schedule]);
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
