<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\NotFoundException;
use App\Models\Schedule;
use PDO;

/**
 * "Where are you and what are you doing?"
 *
 * The reply is picked from a set matched to the robot's current status, then
 * filled with real telemetry — the site it is standing in (or its distance from
 * the nearest one), the task it is running, and its remaining duty budget. The
 * wording varies; the facts do not.
 */
class RobotPing
{
    /** Charging robots answer in their own voice. */
    private const CHARGING_LINES = [
        'Plugged in and unapologetic. Currently at %d%% — ask me again in a bit.',
        'Do not disturb: I am eating. %d%% and climbing.',
        'Sitting at %d%%. I have seen things out there. I need this.',
        'Charging. %d%%. This is my villain origin story and also my nap.',
    ];

    private const IDLE_LINES = [
        'Standing by at %s. Nothing on my schedule.',
        'Parked at %s, systems nominal, awaiting tasking.',
        'At %s and idle. %s of duty time left today.',
        'Holding position at %s. Ready when you are.',
    ];

    /**
     * A robot between sites needs its own phrasing -- "Parked at in transit,
     * 430 m from Armoury Bunker" is not a sentence.
     */
    private const TRANSIT_LINES = [
        'On the move, %s out from %s. Nothing tasked yet.',
        'In transit — currently %s short of %s.',
        'Crossing the city, %s from %s. %s of duty time left.',
        'Between sites, %s out from %s. Ready when I arrive.',
    ];

    private const TRANSIT_BUSY_LINES = [
        'En route on "%s", %s out from %s.',
        'Running "%s" — in transit, %s from %s.',
        'On task "%s", still %s short of %s.',
        'Moving for "%s". %s out from %s.',
    ];

    private const BUSY_LINES = [
        'Working "%s" at %s. Back when it is done.',
        'Mid-task: "%s". Currently at %s.',
        'Busy with "%s" — %s, running to schedule.',
        'Executing "%s" out of %s. Please hold.',
    ];

    /**
     * A robot can be busy with no scheduled row behind it -- taken manually, or
     * left that way by an operator. Quoting a placeholder task name reads as a
     * bug ('Mid-task: "its current assignment"'), so these lines carry no quotes.
     */
    private const BUSY_UNKNOWN_LINES = [
        'Occupied at %s. Nothing on my schedule for it, though.',
        'Working at %s — off-book, no scheduled task against my name.',
        'Busy at %s. Someone tasked me directly.',
        'Engaged at %s with no matching booking. Ask whoever grabbed me.',
    ];

    private const MAINTENANCE_LINES = [
        'Out of service at %s. Someone has my panels off.',
        'In maintenance at %s. Not going anywhere.',
        'Down for work at %s — check the maintenance log.',
        'Currently a pile of parts at %s. Back soon.',
    ];

    private const ERROR_LINES = [
        'Fault reported at %s. I am not moving until someone looks at me.',
        'Error state at %s. Diagnostics needed.',
        'Something is wrong. Last known good position: %s.',
        'Halted at %s with a fault flag set.',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function ping(int $robotId): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.id, r.name, r.status, COALESCE(r.battery_level, 0) AS battery_level,
                    r.current_location_lat, r.current_location_lng,
                    r.max_duty_minutes, r.duty_minutes_used,
                    r.return_reserve_minutes, r.duty_class,
                    t.title AS current_task
             FROM robots r
             LEFT JOIN schedules s
                    ON s.robot_id = r.id
                   AND s.status = \'scheduled\'
                   AND CURRENT_TIMESTAMP >= s.start_time
                   AND CURRENT_TIMESTAMP <  s.end_time
             LEFT JOIN tasks t ON t.id = s.task_id
             WHERE r.id = ?
             LIMIT 1'
        );
        $stmt->execute([$robotId]);
        $robot = $stmt->fetch();

        if ($robot === false) {
            throw NotFoundException::robot($robotId);
        }

        $geo      = new Geography($this->db);
        $site     = $geo->locate($robotId);
        $nearest  = $site ?? $geo->nearest($robotId);
        $battery  = (int) $robot['battery_level'];
        $status   = (string) $robot['status'];

        $duty = Schedule::dutyBreakdown(
            (int) $robot['max_duty_minutes'],
            (int) $robot['duty_minutes_used'],
            (int) $robot['return_reserve_minutes'],
            (string) $robot['duty_class'],
        );
        $remaining = $duty['schedulable_remaining'];

        return [
            'robot_id'  => (int) $robot['id'],
            'name'      => $robot['name'],
            'status'    => $status,
            'message'   => $this->message($status, $site, $nearest, $robot, $battery, $remaining),
            'telemetry' => [
                'battery_level'      => $battery,
                'location'           => $site === null ? null : $site['name'],
                'in_transit'         => $site === null,
                'nearest_site'       => $nearest['name'] ?? null,
                'distance_m'         => $nearest['distance_m'] ?? null,
                'coordinates'        => [
                    'lat' => $robot['current_location_lat'] === null ? null : (float) $robot['current_location_lat'],
                    'lng' => $robot['current_location_lng'] === null ? null : (float) $robot['current_location_lng'],
                ],
                'current_task'       => $robot['current_task'],
                'duty_minutes_used'  => (int) $robot['duty_minutes_used'],
                'duty_minutes_left'  => $remaining,
                // Full breakdown so a department can see why the bookable
                // figure is smaller than the raw endurance.
                'duty'               => $duty,
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $site    the site the robot is standing in
     * @param array<string, mixed>|null $nearest closest site, used when in transit
     * @param array<string, mixed>      $robot
     */
    private function message(
        string $status,
        ?array $site,
        ?array $nearest,
        array $robot,
        int $battery,
        int $remaining,
    ): string {
        // Deterministic per robot per minute: repeated pings read as the same
        // robot talking, not a random quote generator, but it still varies.
        $seed = ((int) $robot['id']) + (int) floor(time() / 60);
        $i    = $seed % 4;
        $task = $robot['current_task'];

        // Charging robots are docked by definition, so place is irrelevant.
        if ($status === 'charging') {
            return sprintf(self::CHARGING_LINES[$i], $battery);
        }

        if ($site === null) {
            return $this->transitMessage($i, $status, $nearest, $task, $remaining);
        }

        $place = $site['name'];

        return match ($status) {
            'busy'        => $task === null
                ? sprintf(self::BUSY_UNKNOWN_LINES[$i], $place)
                : sprintf(self::BUSY_LINES[$i], $task, $place),
            'maintenance' => sprintf(self::MAINTENANCE_LINES[$i], $place),
            'error'       => sprintf(self::ERROR_LINES[$i], $place),
            default       => $this->idleMessage($i, $place, $remaining),
        };
    }

    /** @param array<string, mixed>|null $nearest */
    private function transitMessage(int $i, string $status, ?array $nearest, ?string $task, int $remaining): string
    {
        if ($nearest === null) {
            return 'Position unknown — I am off the map and not enjoying it.';
        }

        $distance = $this->humanMetres((float) $nearest['distance_m']);
        $name     = $nearest['name'];

        if ($status === 'busy') {
            // No booking behind the busy flag: describe the movement, not a
            // task name that does not exist.
            return $task === null
                ? sprintf('On the move %s out from %s, tasked off-book.', $distance, $name)
                : sprintf(self::TRANSIT_BUSY_LINES[$i], $task, $distance, $name);
        }
        // Maintenance and error are distinct conditions; reporting a robot under
        // maintenance as "Fault reported" would send a technician chasing a
        // failure that is not happening.
        $where = sprintf('a point %s from %s', $distance, $name);
        if ($status === 'maintenance') {
            return sprintf(self::MAINTENANCE_LINES[$i], $where);
        }
        if ($status === 'error') {
            return sprintf(self::ERROR_LINES[$i], $where);
        }

        $line = self::TRANSIT_LINES[$i];

        return substr_count($line, '%s') === 3
            ? sprintf($line, $distance, $name, $this->humanMinutes($remaining))
            : sprintf($line, $distance, $name);
    }

    private function idleMessage(int $i, string $place, int $remaining): string
    {
        $line = self::IDLE_LINES[$i];

        // One of the idle lines carries the duty budget; the rest take place only.
        return substr_count($line, '%s') === 2
            ? sprintf($line, $place, $this->humanMinutes($remaining))
            : sprintf($line, $place);
    }

    private function humanMetres(float $m): string
    {
        return $m >= 1000 ? sprintf('%.1f km', $m / 1000) : sprintf('%d m', (int) round($m));
    }

    private function humanMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} min";
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m === 0 ? "{$h}h" : "{$h}h {$m}m";
    }
}
