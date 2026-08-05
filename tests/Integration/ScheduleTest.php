<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Models\Schedule;
use DateTimeImmutable;

/**
 * Covers the four defects the original Schedule::scheduleTask() carried:
 * no transaction, false-fetch dereference, hardcoded +1 hour end time, and a
 * status gate that both blocked future bookings and never released the robot.
 */
class ScheduleTest extends DatabaseTestCase
{
    private function schedule(): Schedule
    {
        return new Schedule($this->db);
    }

    public function testEndTimeComesFromTheTaskDurationNotAHardcodedHour(): void
    {
        $robot = $this->insertRobot();
        $task  = $this->insertTask(duration: 90);
        $start = $this->futureTime();

        $row = $this->schedule()->scheduleTask($robot, $task, $start);

        $this->assertSame(
            $start->modify('+90 minutes')->format('Y-m-d H:i:s'),
            (new DateTimeImmutable($row['end_time']))->format('Y-m-d H:i:s')
        );
    }

    public function testRejectsAnOverlappingBookingAndLeavesNoOrphanRow(): void
    {
        $robot = $this->insertRobot();
        $task  = $this->insertTask(duration: 60);
        $start = $this->futureTime();

        $this->schedule()->scheduleTask($robot, $task, $start);
        $this->assertSame(1, $this->countSchedules($robot));

        try {
            $this->schedule()->scheduleTask($robot, $task, $start->modify('+30 minutes'));
            $this->fail('Expected ConflictException for an overlapping window');
        } catch (ConflictException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        // The rollback is the point: without a transaction the second attempt
        // could still have written a row before failing.
        $this->assertSame(1, $this->countSchedules($robot));
        $this->assertFalse($this->db->inTransaction());
    }

    /** OVERLAPS is half-open, so back-to-back bookings are legal. */
    public function testAdjacentBookingIsAllowed(): void
    {
        $robot = $this->insertRobot();
        $task  = $this->insertTask(duration: 60);
        $start = $this->futureTime();

        $this->schedule()->scheduleTask($robot, $task, $start);
        $this->schedule()->scheduleTask($robot, $task, $start->modify('+60 minutes'));

        $this->assertSame(2, $this->countSchedules($robot));
    }

    /**
     * The old `status !== 'idle'` gate refused this outright. A robot working
     * now is still bookable for a window that does not overlap.
     */
    public function testBusyRobotCanStillBeBookedForANonOverlappingFutureWindow(): void
    {
        $robot = $this->insertRobot(status: 'busy');
        $task  = $this->insertTask(duration: 30);

        $row = $this->schedule()->scheduleTask($robot, $task, $this->futureTime());

        $this->assertNotEmpty($row['id']);
    }

    public function testFutureBookingDoesNotMarkTheRobotBusyToday(): void
    {
        $robot = $this->insertRobot(status: 'idle');
        $task  = $this->insertTask(duration: 30);

        $this->schedule()->scheduleTask($robot, $task, $this->futureTime());

        $this->assertSame('idle', $this->robotStatus($robot));
    }

    public function testLiveWindowMarksTheRobotBusy(): void
    {
        $robot = $this->insertRobot(status: 'idle');
        $task  = $this->insertTask(duration: 60);

        $this->schedule()->scheduleTask($robot, $task, new DateTimeImmutable('-10 minutes'));

        $this->assertSame('busy', $this->robotStatus($robot));
    }

    public function testRejectsRobotInMaintenance(): void
    {
        $robot = $this->insertRobot(status: 'maintenance');
        $task  = $this->insertTask();

        $this->expectException(ConflictException::class);
        $this->schedule()->scheduleTask($robot, $task, $this->futureTime());
    }

    public function testRejectsRobotLackingTheRequiredCapability(): void
    {
        $robot      = $this->insertRobot();
        $capability = $this->insertCapability('Precision Surgery');
        $task       = $this->insertTask(duration: 30, capabilityId: $capability);

        try {
            $this->schedule()->scheduleTask($robot, $task, $this->futureTime());
            $this->fail('Expected ConflictException for a missing capability');
        } catch (ConflictException $e) {
            $this->assertStringContainsString('capability', strtolower($e->getMessage()));
        }

        $this->assertSame(0, $this->countSchedules($robot));
    }

    public function testAcceptsRobotHoldingTheRequiredCapability(): void
    {
        $robot      = $this->insertRobot();
        $capability = $this->insertCapability('Heavy Lifting');
        $task       = $this->insertTask(duration: 30, capabilityId: $capability);
        $this->grantCapability($robot, $capability);

        $row = $this->schedule()->scheduleTask($robot, $task, $this->futureTime());

        $this->assertNotEmpty($row['id']);
    }

    /**
     * The old code dereferenced a `false` fetch here and threw the misleading
     * "Robot is currently busy or in maintenance".
     */
    public function testUnknownRobotThrowsNotFoundNotBusy(): void
    {
        $task = $this->insertTask();

        try {
            $this->schedule()->scheduleTask(2_000_000_000, $task, $this->futureTime());
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertStringContainsString('not found', strtolower($e->getMessage()));
            $this->assertStringNotContainsString('busy', strtolower($e->getMessage()));
        }
    }

    public function testUnknownTaskThrowsNotFound(): void
    {
        $robot = $this->insertRobot();

        $this->expectException(NotFoundException::class);
        $this->schedule()->scheduleTask($robot, 2_000_000_000, $this->futureTime());
    }

    public function testCompleteReleasesTheRobot(): void
    {
        $robot = $this->insertRobot(status: 'idle');
        $task  = $this->insertTask(duration: 60);

        $row = $this->schedule()->scheduleTask($robot, $task, new DateTimeImmutable('-10 minutes'));
        $this->assertSame('busy', $this->robotStatus($robot));

        $completed = $this->schedule()->complete((int) $row['id']);

        $this->assertSame('completed', $completed['status']);
        $this->assertSame('idle', $this->robotStatus($robot));
    }

    public function testCompleteDoesNotClobberMaintenanceStatus(): void
    {
        $robot = $this->insertRobot(status: 'idle');
        $task  = $this->insertTask(duration: 60);
        $row   = $this->schedule()->scheduleTask($robot, $task, new DateTimeImmutable('-10 minutes'));

        $this->db->prepare('UPDATE robots SET status = ? WHERE id = ?')->execute(['maintenance', $robot]);
        $this->schedule()->complete((int) $row['id']);

        $this->assertSame('maintenance', $this->robotStatus($robot));
    }

    public function testCompletingTwiceConflicts(): void
    {
        $robot = $this->insertRobot();
        $task  = $this->insertTask(duration: 30);
        $row   = $this->schedule()->scheduleTask($robot, $task, $this->futureTime());

        $this->schedule()->complete((int) $row['id']);

        $this->expectException(ConflictException::class);
        $this->schedule()->complete((int) $row['id']);
    }

    public function testCompletingAnUnknownScheduleThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->schedule()->complete(2_000_000_000);
    }

    public function testGetFullScheduleJoinsAndPaginates(): void
    {
        $robot = $this->insertRobot();
        $task  = $this->insertTask(duration: 30);
        $start = $this->futureTime();

        $this->schedule()->scheduleTask($robot, $task, $start);
        $this->schedule()->scheduleTask($robot, $task, $start->modify('+30 minutes'));

        $rows = $this->schedule()->getFullSchedule(limit: 1, offset: 0, robotId: $robot);

        $this->assertCount(1, $rows);
        $this->assertSame('TestBot', $rows[0]['robot_name']);
        $this->assertSame('Test Task', $rows[0]['task_title']);
    }
}
