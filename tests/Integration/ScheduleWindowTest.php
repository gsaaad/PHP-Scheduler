<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Auth\AccessPolicy;
use App\Auth\AuthContext;
use App\Models\Schedule;
use DateTimeImmutable;

/**
 * The window query backing both the month calendar and the resource timeline.
 * They share one query, so a bug here shows up in both views at once.
 */
class ScheduleWindowTest extends DatabaseTestCase
{
    private function schedule(): Schedule
    {
        return new Schedule($this->db);
    }

    private function unrestricted(): array
    {
        return ['sql' => 'TRUE', 'params' => []];
    }

    private function taskOf(int $minutes): int
    {
        $id = $this->insertTask($minutes, null);
        $this->db->prepare('UPDATE tasks SET min_battery_level = 0 WHERE id = ?')->execute([$id]);

        return $id;
    }

    private function window(DateTimeImmutable $from, DateTimeImmutable $to, ?int $robotId = null): array
    {
        return $this->schedule()->inWindow(
            $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s'),
            $this->unrestricted(),
            $robotId
        );
    }

    public function testReturnsBookingsInsideTheWindow(): void
    {
        $robot = $this->insertRobot();
        $task  = $this->taskOf(60);
        $start = $this->futureTime();

        $row = $this->schedule()->scheduleTask($robot, $task, $start);

        $found = $this->window($start->modify('-1 hour'), $start->modify('+3 hours'));
        $ids   = array_map('intval', array_column($found, 'id'));

        $this->assertContains((int) $row['id'], $ids);
    }

    public function testExcludesBookingsOutsideTheWindow(): void
    {
        $robot = $this->insertRobot();
        $task  = $this->taskOf(60);
        $start = $this->futureTime();

        $row = $this->schedule()->scheduleTask($robot, $task, $start);

        $found = $this->window($start->modify('+2 days'), $start->modify('+3 days'));
        $ids   = array_map('intval', array_column($found, 'id'));

        $this->assertNotContains((int) $row['id'], $ids);
    }

    /**
     * A booking straddling the window edge still belongs on the calendar --
     * containment would silently drop the job you most need to see.
     */
    public function testIncludesBookingsStraddlingTheWindowEdge(): void
    {
        $robot = $this->insertRobot();
        $task  = $this->taskOf(120);
        $start = $this->futureTime();

        $row = $this->schedule()->scheduleTask($robot, $task, $start);

        // Window opens an hour into a two-hour booking and closes after it ends.
        $found = $this->window($start->modify('+1 hour'), $start->modify('+5 hours'));
        $ids   = array_map('intval', array_column($found, 'id'));

        $this->assertContains((int) $row['id'], $ids, 'a booking overlapping the window must appear');
    }

    public function testJoinsRobotAndTaskDetailForRendering(): void
    {
        $robot = $this->insertRobot();
        $task  = $this->taskOf(45);
        $start = $this->futureTime();
        $this->schedule()->scheduleTask($robot, $task, $start);

        $found = $this->window($start->modify('-1 hour'), $start->modify('+3 hours'), $robot);

        $this->assertCount(1, $found);
        $row = $found[0];
        foreach (['robot_name', 'task_title', 'duty_class', 'max_duty_minutes',
                  'return_reserve_minutes', 'duty_minutes', 'battery_level'] as $key) {
            $this->assertArrayHasKey($key, $row, "window row is missing {$key}");
        }
        $this->assertSame('TestBot', $row['robot_name']);
        $this->assertSame(45, (int) $row['duty_minutes']);
    }

    /** The calendar must not leak another lab's bookings. */
    public function testWindowRespectsAccessScope(): void
    {
        $dept  = $this->insertDepartment();
        $arena = $this->insertArena('Scoped Arena');
        $task  = $this->taskOf(60);
        $start = $this->futureTime();

        $mine   = $this->insertRobot();
        $theirs = $this->insertRobot();
        $this->assignArena($mine, $arena);
        $this->insertAccessRule($dept, 'My arena', [['arena', $arena, null]]);

        $mineRow   = $this->schedule()->scheduleTask($mine, $task, $start);
        $theirsRow = $this->schedule()->scheduleTask($theirs, $task, $start);

        $auth   = new AuthContext(1, 'u', $dept, [], false, true, true, 'token');
        $access = (new AccessPolicy($this->db))->robotFilter($auth, 'r');

        $found = $this->schedule()->inWindow(
            $start->modify('-1 hour')->format('Y-m-d H:i:s'),
            $start->modify('+3 hours')->format('Y-m-d H:i:s'),
            $access
        );
        $ids = array_map('intval', array_column($found, 'id'));

        $this->assertContains((int) $mineRow['id'], $ids);
        $this->assertNotContains((int) $theirsRow['id'], $ids);
    }

    /** Gantt rows include robots with nothing booked -- an empty row is information. */
    public function testGanttRowsIncludeIdleRobots(): void
    {
        $dept  = $this->insertDepartment();
        $arena = $this->insertArena('Gantt Arena');
        $quiet = $this->insertRobot();
        $this->assignArena($quiet, $arena);
        $this->insertAccessRule($dept, 'Gantt scope', [['arena', $arena, null]]);

        $auth   = new AuthContext(1, 'u', $dept, [], false, true, true, 'token');
        $access = (new AccessPolicy($this->db))->robotFilter($auth, 'r');

        $rows = $this->schedule()->robotsForGantt($access, 50, 0);
        $ids  = array_map('intval', array_column($rows, 'id'));

        $this->assertContains($quiet, $ids);
        $this->assertArrayHasKey('return_reserve_minutes', $rows[0]);
        $this->assertArrayHasKey('duty_class', $rows[0]);
    }

    public function testCancelledBookingsAreExcluded(): void
    {
        $robot = $this->insertRobot();
        $task  = $this->taskOf(60);
        $start = $this->futureTime();
        $row   = $this->schedule()->scheduleTask($robot, $task, $start);

        $this->db->prepare("UPDATE schedules SET status = 'cancelled' WHERE id = ?")
            ->execute([$row['id']]);

        $found = $this->window($start->modify('-1 hour'), $start->modify('+3 hours'));

        $this->assertNotContains((int) $row['id'], array_map('intval', array_column($found, 'id')));
    }
}
