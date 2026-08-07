<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ConflictException;
use App\Models\Geography;
use App\Models\RobotPing;
use App\Models\Schedule;
use DateTimeImmutable;

/**
 * RobotCity geography, the shared duty budget, and the ping response.
 */
class DutyAndGeographyTest extends DatabaseTestCase
{
    /**
     * Fixtures live far from RobotCity (which is seeded around 40.71, -74.01),
     * so a test site is never out-competed by a real one. Dock Golf sits on the
     * city centre exactly, which is what made the first cut of these tests fail.
     */
    private const T_LAT = 10.0;
    private const T_LNG = 10.0;

    /** ~111 m per 0.001 degrees of latitude at this scale. */
    private static function lat(float $metresNorth): float
    {
        return self::T_LAT + $metresNorth / 111_000;
    }

    private function schedule(): Schedule
    {
        return new Schedule($this->db);
    }

    private function placeRobot(int $robotId, float $lat, float $lng): void
    {
        $this->db->prepare(
            'UPDATE robots SET current_location_lat = ?, current_location_lng = ? WHERE id = ?'
        )->execute([$lat, $lng, $robotId]);
    }

    private function placeSite(int $arenaId, float $lat, float $lng, string $domain = 'research', int $radius = 200): void
    {
        $this->db->prepare(
            'UPDATE arenas SET latitude = ?, longitude = ?, domain = ?, radius_m = ? WHERE id = ?'
        )->execute([$lat, $lng, $domain, $radius, $arenaId]);
    }

    private function setDuty(int $robotId, int $max, int $used = 0, int $reserve = 30, string $class = 'standard'): void
    {
        $this->db->prepare(
            'UPDATE robots SET max_duty_minutes = ?, duty_minutes_used = ?,
                    return_reserve_minutes = ?, duty_class = ? WHERE id = ?'
        )->execute([$max, $used, $reserve, $class, $robotId]);
    }

    private function taskOf(int $minutes, ?int $capabilityId = null): int
    {
        $id = $this->insertTask($minutes, $capabilityId);
        $this->db->prepare('UPDATE tasks SET min_battery_level = 0 WHERE id = ?')->execute([$id]);

        return $id;
    }

    // ------------------------------------------------------- geography

    public function testRobotInsideARadiusReportsThatSite(): void
    {
        $robot = $this->insertRobot();
        $arena = $this->insertArena('Chem Lab Test');
        $this->placeSite($arena, self::T_LAT, self::T_LNG);
        $this->placeRobot($robot, self::lat(6), self::T_LNG); // ~6 m away

        $located = (new Geography($this->db))->locate($robot);

        $this->assertNotNull($located);
        $this->assertSame($arena, $located['id']);
        $this->assertLessThan(200, $located['distance_m']);
    }

    public function testRobotOutsideEveryRadiusIsInTransit(): void
    {
        $robot = $this->insertRobot();
        $arena = $this->insertArena('Far Site');
        $this->placeSite($arena, self::T_LAT, self::T_LNG, radius: 100);
        $this->placeRobot($robot, self::lat(1100), self::T_LNG); // ~1.1 km north

        $geo = new Geography($this->db);

        $this->assertNull($geo->locate($robot), 'robot should not be inside any radius');

        $nearest = $geo->nearest($robot);
        $this->assertNotNull($nearest);
        $this->assertSame($arena, $nearest['id']);
        $this->assertGreaterThan(1000, $nearest['distance_m']);
    }

    public function testNearestChargingStationIgnoresNonChargingSites(): void
    {
        $robot   = $this->insertRobot();
        $closeOp = $this->insertArena('Close Operational');
        $farDock = $this->insertArena('Far Dock');

        $this->placeSite($closeOp, self::T_LAT, self::T_LNG, 'research');
        $this->placeSite($farDock, self::lat(1100), self::T_LNG, Geography::CHARGING);
        $this->placeRobot($robot, self::T_LAT, self::T_LNG);

        $station = (new Geography($this->db))->nearestChargingStation($robot);

        $this->assertNotNull($station);
        $this->assertSame($farDock, $station['id'], 'must pick the dock, not the closer operational site');
    }

    // ---------------------------------------------------- booking cap

    public function testBookingLongerThanThreeHoursIsRefused(): void
    {
        $robot = $this->insertRobot();
        $task  = $this->taskOf(240);
        $this->setDuty($robot, 600);

        try {
            $this->schedule()->scheduleTask($robot, $task, $this->futureTime());
            $this->fail('Expected ConflictException for a 4-hour booking');
        } catch (ConflictException $e) {
            $this->assertStringContainsString('180 minutes', $e->getMessage());
        }

        $this->assertSame(0, $this->countSchedules($robot));
    }

    public function testExactlyThreeHoursIsAllowed(): void
    {
        $robot = $this->insertRobot();
        $task  = $this->taskOf(180);
        $this->setDuty($robot, 270);

        $row = $this->schedule()->scheduleTask($robot, $task, $this->futureTime());

        $this->assertNotEmpty($row['id']);
        $this->assertSame(180, (int) $row['duty_minutes']);
    }

    // --------------------------------------------------- duty budget

    /**
     * The headline case, exactly as specified: a 4h30 platform booked for three
     * hours leaves the next department one hour -- not 1h30 -- because the last
     * 30 minutes are the robot's trip back to a charging dock.
     *
     *     270 endurance - 180 booked - 30 return = 60 bookable
     */
    public function testReturnReserveIsHeldBackFromTheNextDepartment(): void
    {
        $robot = $this->insertRobot();
        $long  = $this->taskOf(180);
        $this->setDuty($robot, 270, reserve: 30);

        $first = $this->schedule()->scheduleTask($robot, $long, $this->futureTime());

        $this->assertSame(60, $first['duty_minutes_remaining'], '4h30 - 3h - 30m return = 1h');
        $this->assertSame(240, $first['duty']['schedulable_total']);
        $this->assertSame(30, $first['duty']['return_reserve']);
        $this->assertStringContainsString('return to a charging dock', $first['duty']['note']);
    }

    public function testDutyBudgetIsSharedAcrossDepartments(): void
    {
        $robot = $this->insertRobot();
        $long  = $this->taskOf(180);
        $this->setDuty($robot, 270, reserve: 30);

        $this->schedule()->scheduleTask($robot, $long, $this->futureTime());

        try {
            $this->schedule()->scheduleTask($robot, $long, $this->futureTime('+2 days'));
            $this->fail('Expected ConflictException: only 60 bookable minutes remain');
        } catch (ConflictException $e) {
            $this->assertStringContainsString('held back for the return trip', $e->getMessage());
        }

        // A job that fits the remainder still goes through.
        $this->schedule()->scheduleTask($robot, $this->taskOf(60), $this->futureTime('+3 days'));

        $this->assertSame(2, $this->countSchedules($robot));
    }

    /** A lightweight platform genuinely offers most of a working day. */
    public function testLightweightPlatformOffersSevenHoursMinusTheReserve(): void
    {
        $robot = $this->insertRobot();
        $this->setDuty($robot, 420, reserve: 30, class: 'light');

        $row = $this->schedule()->scheduleTask($robot, $this->taskOf(180), $this->futureTime());

        $this->assertSame(390, $row['duty']['schedulable_total'], '7h endurance - 30m return');
        $this->assertSame(210, $row['duty_minutes_remaining']);
        $this->assertSame('light', $row['duty']['duty_class']);
    }

    /** The reserve must never be sold, even when the raw remainder looks sufficient. */
    public function testReserveCannotBeBookedEvenWhenEnduranceRemains(): void
    {
        $robot = $this->insertRobot();
        // 60 minutes of raw endurance left, but all of it is the return trip.
        $this->setDuty($robot, 270, used: 210, reserve: 60);

        $this->expectException(ConflictException::class);
        $this->schedule()->scheduleTask($robot, $this->taskOf(30), $this->futureTime());
    }

    public function testExhaustedBudgetBlocksEvenAShortTask(): void
    {
        $robot = $this->insertRobot();
        $this->setDuty($robot, 270, used: 240, reserve: 30);

        $this->expectException(ConflictException::class);
        $this->schedule()->scheduleTask($robot, $this->taskOf(15), $this->futureTime());
    }

    // ------------------------------------------------ charge dispatch

    public function testCompletingASpentRobotDispatchesItToTheNearestDock(): void
    {
        $robot = $this->insertRobot(status: 'idle');
        $dock  = $this->insertArena('Test Dock');
        $this->placeSite($dock, self::T_LAT, self::T_LNG, Geography::CHARGING);
        $this->placeRobot($robot, self::lat(20), self::T_LNG);

        // 210 endurance - 30 reserve = 180 bookable; a 180-minute job spends
        // all of it, leaving 0 and tripping the dispatch threshold.
        $this->setDuty($robot, 210, reserve: 30);
        $row = $this->schedule()->scheduleTask($robot, $this->taskOf(180), new DateTimeImmutable('-10 minutes'));

        $completed = $this->schedule()->complete((int) $row['id']);

        $this->assertNotNull($completed['dispatched_to_charge'] ?? null);
        $this->assertSame($dock, $completed['dispatched_to_charge']['arena_id']);
        $this->assertSame('charging', $this->robotStatus($robot));

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM charge_sessions WHERE robot_id = ? AND completed_at IS NULL');
        $stmt->execute([$robot]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testRobotWithUsableTimeLeftIsNotDispatched(): void
    {
        $robot = $this->insertRobot(status: 'idle');
        $this->setDuty($robot, 400); // 220 minutes will remain

        $row = $this->schedule()->scheduleTask($robot, $this->taskOf(180), new DateTimeImmutable('-10 minutes'));
        $completed = $this->schedule()->complete((int) $row['id']);

        $this->assertNull($completed['dispatched_to_charge'] ?? null);
        $this->assertSame('idle', $this->robotStatus($robot));
    }

    public function testCompletingAChargeResetsTheBudgetAndMovesTheRobot(): void
    {
        $robot = $this->insertRobot(status: 'idle');
        $dock  = $this->insertArena('Reset Dock');
        $this->placeSite($dock, self::T_LAT + 1, self::T_LNG + 1, Geography::CHARGING);
        $this->placeRobot($robot, self::T_LAT + 1.00002, self::T_LNG + 1);
        $this->setDuty($robot, 210, reserve: 30);

        $row = $this->schedule()->scheduleTask($robot, $this->taskOf(180), new DateTimeImmutable('-10 minutes'));
        $this->schedule()->complete((int) $row['id']);
        $this->assertSame('charging', $this->robotStatus($robot));

        $after = $this->schedule()->completeCharge($robot);

        $this->assertSame('idle', $after['status']);
        $this->assertSame(0, (int) $after['duty_minutes_used']);
        $this->assertSame(100, (int) $after['battery_level']);

        // Positioned at the dock it charged in
        $located = (new Geography($this->db))->locate($robot);
        $this->assertSame($dock, $located['id'] ?? null);
    }

    public function testCompletingAChargeOnANonChargingRobotConflicts(): void
    {
        $robot = $this->insertRobot(status: 'idle');

        $this->expectException(ConflictException::class);
        $this->schedule()->completeCharge($robot);
    }

    // ------------------------------------------------------------ ping

    public function testChargingRobotAnswersWithBatteryLevel(): void
    {
        $robot = $this->insertRobot(status: 'charging');
        $this->setBattery($robot, 61);

        $reply = (new RobotPing($this->db))->ping($robot);

        $this->assertSame('charging', $reply['status']);
        $this->assertStringContainsString('61', $reply['message']);
        $this->assertSame(61, $reply['telemetry']['battery_level']);
    }

    public function testIdleRobotInsideASiteNamesIt(): void
    {
        $robot = $this->insertRobot(status: 'idle');
        $arena = $this->insertArena('Ping Site');
        $this->placeSite($arena, self::T_LAT, self::T_LNG);
        $this->placeRobot($robot, self::lat(3), self::T_LNG);

        $reply = (new RobotPing($this->db))->ping($robot);

        $this->assertStringContainsString('Ping Site', $reply['message']);
        $this->assertFalse($reply['telemetry']['in_transit']);
    }

    /** A robot between sites must not produce "Parked at in transit, 430 m from X". */
    public function testInTransitRobotGetsTransitPhrasing(): void
    {
        $robot = $this->insertRobot(status: 'idle');
        $arena = $this->insertArena('Distant Site');
        $this->placeSite($arena, self::T_LAT, self::T_LNG, radius: 100);
        $this->placeRobot($robot, self::lat(350), self::T_LNG);

        $reply = (new RobotPing($this->db))->ping($robot);

        $this->assertTrue($reply['telemetry']['in_transit']);
        $this->assertStringNotContainsString('at in transit', $reply['message']);
        $this->assertStringContainsString('Distant Site', $reply['message']);
    }

    /** A busy robot with no booking behind it must not quote a placeholder task name. */
    public function testBusyRobotWithoutABookingDoesNotQuoteAFakeTask(): void
    {
        $robot = $this->insertRobot(status: 'busy');
        $arena = $this->insertArena('Off Book Site');
        $this->placeSite($arena, self::T_LAT, self::T_LNG);
        $this->placeRobot($robot, self::lat(5), self::T_LNG);

        $reply = (new RobotPing($this->db))->ping($robot);

        $this->assertStringNotContainsString('its current assignment', $reply['message']);
        $this->assertStringNotContainsString('""', $reply['message']);
        $this->assertStringContainsString('Off Book Site', $reply['message']);
        $this->assertNull($reply['telemetry']['current_task']);
    }

    /** Maintenance is not a fault; the two must not share wording. */
    public function testMaintenanceInTransitDoesNotReportAFault(): void
    {
        $robot = $this->insertRobot(status: 'maintenance');
        $arena = $this->insertArena('Distant Depot');
        $this->placeSite($arena, self::T_LAT, self::T_LNG, radius: 100);
        $this->placeRobot($robot, self::lat(400), self::T_LNG);

        $reply = (new RobotPing($this->db))->ping($robot);

        $this->assertTrue($reply['telemetry']['in_transit']);
        $this->assertStringNotContainsString('Fault', $reply['message']);
        $this->assertStringNotContainsString('fault flag', $reply['message']);
        $this->assertStringContainsString('Distant Depot', $reply['message']);
    }

    public function testPingReportsRemainingDutyBudget(): void
    {
        $robot = $this->insertRobot(status: 'idle');
        $this->setDuty($robot, 270, used: 180, reserve: 30);

        $reply = (new RobotPing($this->db))->ping($robot);

        // 270 endurance - 180 used - 30 return reserve
        $this->assertSame(60, $reply['telemetry']['duty_minutes_left']);
        $this->assertSame(180, $reply['telemetry']['duty_minutes_used']);
    }
}
