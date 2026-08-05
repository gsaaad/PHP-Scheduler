<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\NotFoundException;
use App\Models\GenericRobot;
use App\Models\HealthcareRobot;
use App\Models\RobotRepository;
use App\Models\RobotStatus;
use App\Models\WarehouseRobot;

class RobotRepositoryTest extends DatabaseTestCase
{
    private function repo(): RobotRepository
    {
        return new RobotRepository($this->db);
    }

    public function testCreateReturnsAHydratedRobotOfTheRightSubclass(): void
    {
        $robot = $this->repo()->create(['name' => 'MediBot-99', 'type' => 'healthcare', 'battery_level' => 88]);

        $this->assertInstanceOf(HealthcareRobot::class, $robot);
        $this->assertSame('MediBot-99', $robot->getName());
        $this->assertSame(88, $robot->getBatteryLevel());
        $this->assertNotNull($robot->getId());

        // Track for cleanup
        $this->db->prepare('DELETE FROM robots WHERE id = ?')->execute([$robot->getId()]);
    }

    public function testFindReturnsNullForAMissingRobot(): void
    {
        $this->assertNull($this->repo()->find(2_000_000_000));
    }

    public function testFindOrFailThrows(): void
    {
        $this->expectException(NotFoundException::class);
        $this->repo()->findOrFail(2_000_000_000);
    }

    public function testUpdateStatusReturnsTheUpdatedRobot(): void
    {
        $id = $this->insertRobot(status: 'idle');

        $robot = $this->repo()->updateStatus($id, RobotStatus::Charging);

        $this->assertSame('charging', $robot->getStatus());
        $this->assertSame('charging', $this->robotStatus($id));
    }

    public function testUpdateStatusOnAMissingRobotThrows(): void
    {
        $this->expectException(NotFoundException::class);
        $this->repo()->updateStatus(2_000_000_000, RobotStatus::Idle);
    }

    public function testGetAllIsPaginated(): void
    {
        $this->insertRobot();
        $this->insertRobot();

        $this->assertCount(1, $this->repo()->getAll(limit: 1, offset: 0));
        $this->assertLessThanOrEqual(2, count($this->repo()->getAll(limit: 2, offset: 0)));
    }

    /**
     * Rows loaded from the database must hydrate into concrete subclasses --
     * the seeder emits five types and the factory used to cover only two.
     */
    public function testSeededTypesHydrateIntoConcreteClasses(): void
    {
        $warehouse = $this->repo()->findOrFail($this->insertRobot(type: 'warehouse'));
        $military  = $this->repo()->findOrFail($this->insertRobot(type: 'military'));
        $unknown   = $this->repo()->findOrFail($this->insertRobot(type: 'agricultural'));

        $this->assertInstanceOf(WarehouseRobot::class, $warehouse);
        $this->assertInstanceOf(\App\Models\MilitaryRobot::class, $military);
        $this->assertInstanceOf(GenericRobot::class, $unknown);
    }

    public function testCountReflectsInsertedRows(): void
    {
        $before = $this->repo()->count();
        $this->insertRobot();

        $this->assertSame($before + 1, $this->repo()->count());
    }
}
