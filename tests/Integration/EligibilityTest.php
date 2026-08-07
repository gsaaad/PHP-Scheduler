<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Auth\AccessPolicy;
use App\Auth\AuthContext;
use App\Exceptions\ConflictException;
use App\Models\RobotRepository;
use App\Models\Schedule;

/**
 * Eligibility answers "which robots can take this task" in one query, and the
 * battery gate closes the last unused column in the robots table.
 */
class EligibilityTest extends DatabaseTestCase
{
    private function repo(): RobotRepository
    {
        return new RobotRepository($this->db);
    }

    private function taskNeeding(int $capabilityId, int $minBattery, int $duration = 60): int
    {
        $id = $this->insertTask($duration, $capabilityId);
        $this->db->prepare('UPDATE tasks SET min_battery_level = ? WHERE id = ?')->execute([$minBattery, $id]);

        return $id;
    }

    public function testEligibleListExcludesRobotsMissingTheCapability(): void
    {
        $cap  = $this->insertCapability('Precision');
        $task = $this->taskNeeding($cap, 0);

        $capable   = $this->insertRobot();
        $incapable = $this->insertRobot();
        $this->grantCapability($capable, $cap);

        $ids = array_column($this->repo()->eligibleForTask($task, RobotRepository::UNRESTRICTED, 500), 'id');

        $this->assertContains((string) $capable, array_map('strval', $ids));
        $this->assertNotContains((string) $incapable, array_map('strval', $ids));
    }

    public function testEligibleListExcludesUnavailableStatuses(): void
    {
        $cap  = $this->insertCapability('Precision');
        $task = $this->taskNeeding($cap, 0);

        $idle        = $this->insertRobot(status: 'idle');
        $maintenance = $this->insertRobot(status: 'maintenance');
        $errored     = $this->insertRobot(status: 'error');
        foreach ([$idle, $maintenance, $errored] as $r) {
            $this->grantCapability($r, $cap);
        }

        $ids = array_map('intval', array_column(
            $this->repo()->eligibleForTask($task, RobotRepository::UNRESTRICTED, 500),
            'id'
        ));

        $this->assertContains($idle, $ids);
        $this->assertNotContains($maintenance, $ids);
        $this->assertNotContains($errored, $ids);
    }

    public function testEligibleListExcludesRobotsBelowTheBatteryFloor(): void
    {
        $cap  = $this->insertCapability('Precision');
        $task = $this->taskNeeding($cap, 70);

        $charged = $this->insertRobot();
        $low     = $this->insertRobot();
        $this->grantCapability($charged, $cap);
        $this->grantCapability($low, $cap);
        $this->setBattery($charged, 85);
        $this->setBattery($low, 40);

        $ids = array_map('intval', array_column(
            $this->repo()->eligibleForTask($task, RobotRepository::UNRESTRICTED, 500),
            'id'
        ));

        $this->assertContains($charged, $ids);
        $this->assertNotContains($low, $ids);
    }

    public function testEligibleListRespectsAccessScope(): void
    {
        $cap   = $this->insertCapability('Precision');
        $task  = $this->taskNeeding($cap, 0);
        $dept  = $this->insertDepartment();
        $arena = $this->insertArena();

        $mine   = $this->insertRobot();
        $theirs = $this->insertRobot();
        $this->grantCapability($mine, $cap);
        $this->grantCapability($theirs, $cap);
        $this->assignArena($mine, $arena);
        $this->insertAccessRule($dept, 'My arena', [['arena', $arena, null]]);

        $auth   = new AuthContext(1, 'u', $dept, [], false, true, true, 'token');
        $access = (new AccessPolicy($this->db))->robotFilter($auth);

        $ids = array_map('intval', array_column($this->repo()->eligibleForTask($task, $access, 500), 'id'));

        $this->assertContains($mine, $ids);
        $this->assertNotContains($theirs, $ids);
    }

    public function testEligibleListExcludesRobotsBookedAcrossTheWindow(): void
    {
        $cap  = $this->insertCapability('Precision');
        $task = $this->taskNeeding($cap, 0, 60);

        $free  = $this->insertRobot();
        $taken = $this->insertRobot();
        $this->grantCapability($free, $cap);
        $this->grantCapability($taken, $cap);

        $start = $this->futureTime();
        (new Schedule($this->db))->scheduleTask($taken, $task, $start);

        $ids = array_map('intval', array_column(
            $this->repo()->eligibleForTask(
                $task,
                RobotRepository::UNRESTRICTED,
                500,
                0,
                $start->format('Y-m-d H:i:s'),
                $start->modify('+60 minutes')->format('Y-m-d H:i:s')
            ),
            'id'
        ));

        $this->assertContains($free, $ids);
        $this->assertNotContains($taken, $ids);
    }

    public function testSchedulingRefusesARobotBelowTheBatteryFloor(): void
    {
        $cap   = $this->insertCapability('Precision');
        $task  = $this->taskNeeding($cap, 70);
        $robot = $this->insertRobot();
        $this->grantCapability($robot, $cap);
        $this->setBattery($robot, 25);

        try {
            (new Schedule($this->db))->scheduleTask($robot, $task, $this->futureTime());
            $this->fail('Expected ConflictException for low battery');
        } catch (ConflictException $e) {
            $this->assertStringContainsString('battery', strtolower($e->getMessage()));
        }

        $this->assertSame(0, $this->countSchedules($robot));
    }

    public function testIneligibilityReasonsListEveryFailedGate(): void
    {
        $cap   = $this->insertCapability('Precision');
        $task  = $this->taskNeeding($cap, 90);
        $robot = $this->insertRobot(status: 'maintenance'); // no capability, low battery too
        $this->setBattery($robot, 10);

        $reasons = $this->repo()->ineligibilityReasons($robot, $task);

        // Status, battery and capability all fail -- report all three, not the first.
        $this->assertCount(3, $reasons);
        $joined = strtolower(implode(' ', $reasons));
        $this->assertStringContainsString('maintenance', $joined);
        $this->assertStringContainsString('battery', $joined);
        $this->assertStringContainsString('capability', $joined);
    }

    public function testEligibleRobotReportsNoReasons(): void
    {
        $cap   = $this->insertCapability('Precision');
        $task  = $this->taskNeeding($cap, 20);
        $robot = $this->insertRobot(status: 'idle');
        $this->grantCapability($robot, $cap);
        $this->setBattery($robot, 95);

        $this->assertSame([], $this->repo()->ineligibilityReasons($robot, $task));
    }

    /** @param array{0: int, 1: int, 2: int} $duty endurance, reserve, used */
    private function setDuty(int $robotId, int $endurance, int $reserve, int $used): void
    {
        $this->db->prepare(
            'UPDATE robots SET max_duty_minutes = ?, return_reserve_minutes = ?, duty_minutes_used = ?
             WHERE id = ?'
        )->execute([$endurance, $reserve, $used, $robotId]);
    }

    /**
     * The endpoint exists to stop operators discovering ineligibility by
     * attempting a booking and reading the 409 -- so anything the scheduler
     * refuses on duty grounds must not be offered here either.
     */
    public function testEligibleListExcludesRobotsWithoutTheDutyBudget(): void
    {
        $cap  = $this->insertCapability('Precision');
        $task = $this->taskNeeding($cap, 0, 120);

        $rested = $this->insertRobot();
        $spent  = $this->insertRobot();
        foreach ([$rested, $spent] as $r) {
            $this->grantCapability($r, $cap);
        }

        // 300 - 30 reserve - 60 used = 210 bookable: room for a 120-minute task.
        $this->setDuty($rested, 300, 30, 60);
        // 300 - 30 reserve - 200 used = 70 bookable: not enough, though 100
        // raw minutes remain. The reserve is the difference.
        $this->setDuty($spent, 300, 30, 200);

        $ids = array_map('intval', array_column(
            $this->repo()->eligibleForTask($task, RobotRepository::UNRESTRICTED, 500),
            'id'
        ));

        $this->assertContains($rested, $ids);
        $this->assertNotContains($spent, $ids);
    }

    /** A robot the endpoint offers must survive an actual booking. */
    public function testEveryOfferedRobotCanActuallyBeBooked(): void
    {
        $cap  = $this->insertCapability('Precision');
        $task = $this->taskNeeding($cap, 0, 120);

        $robot = $this->insertRobot();
        $this->grantCapability($robot, $cap);
        $this->setDuty($robot, 300, 30, 200); // 70 bookable -- must not be offered

        $ids = array_map('intval', array_column(
            $this->repo()->eligibleForTask($task, RobotRepository::UNRESTRICTED, 500),
            'id'
        ));
        $this->assertNotContains($robot, $ids);

        // And the scheduler agrees, which is the disagreement this closes.
        $this->expectException(ConflictException::class);
        (new Schedule($this->db))->scheduleTask($robot, $task, $this->futureTime());
    }

    public function testIneligibilityReasonsExplainTheDutyLedger(): void
    {
        $cap   = $this->insertCapability('Precision');
        $task  = $this->taskNeeding($cap, 0, 120);
        $robot = $this->insertRobot();
        $this->grantCapability($robot, $cap);
        $this->setDuty($robot, 300, 30, 200);

        $reasons = $this->repo()->ineligibilityReasons($robot, $task);

        $this->assertCount(1, $reasons);
        // The reason has to name the reserve, or "70 of 300 bookable" reads as
        // arithmetic that does not add up.
        $this->assertStringContainsString('return trip', $reasons[0]);
        $this->assertStringContainsString('70', $reasons[0]);
    }

    public function testTaskLongerThanTheBookingCapIsNeverEligible(): void
    {
        $cap  = $this->insertCapability('Precision');
        // Beyond MAX_BOOKING_MINUTES, so no robot can take it however rested.
        $task = $this->taskNeeding($cap, 0, Schedule::MAX_BOOKING_MINUTES + 30);

        $robot = $this->insertRobot();
        $this->grantCapability($robot, $cap);
        $this->setDuty($robot, 600, 30, 0); // plenty of budget

        $ids = array_map('intval', array_column(
            $this->repo()->eligibleForTask($task, RobotRepository::UNRESTRICTED, 500),
            'id'
        ));

        $this->assertNotContains($robot, $ids);
        $this->assertStringContainsString(
            'single booking',
            implode(' ', $this->repo()->ineligibilityReasons($robot, $task))
        );
    }
}
