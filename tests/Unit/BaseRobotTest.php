<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Factories\RobotFactory;
use App\Models\WarehouseRobot;
use PHPUnit\Framework\TestCase;

class BaseRobotTest extends TestCase
{
    /** PDO returns every pgsql column as a string; the typed properties must absorb that. */
    public function testCastsStringColumnsComingBackFromPdo(): void
    {
        $robot = new WarehouseRobot([
            'id'                   => '42',
            'name'                 => 'R-100',
            'type'                 => 'warehouse',
            'status'               => 'idle',
            'battery_level'        => '87',
            'current_location_lat' => '40.71280000',
            'current_location_lng' => '-74.00600000',
            'created_at'           => '2026-01-15 10:30:00',
        ]);

        $this->assertSame(42, $robot->getId());
        $this->assertSame(87, $robot->getBatteryLevel());
        $this->assertSame(40.7128, $robot->getLocation()['lat']);
        $this->assertSame(-74.006, $robot->getLocation()['lng']);
    }

    public function testNullColumnsFallBackToDefaultsRatherThanTypeErrors(): void
    {
        $robot = new WarehouseRobot([
            'id'            => null,
            'name'          => null,
            'status'        => null,
            'battery_level' => null,
        ]);

        $this->assertNull($robot->getId());
        $this->assertSame('Unknown', $robot->getName());
        $this->assertSame('idle', $robot->getStatus());
        $this->assertSame(100, $robot->getBatteryLevel());
        $this->assertNull($robot->getLocation()['lat']);
    }

    public function testJsonSerializeShape(): void
    {
        $robot = RobotFactory::create([
            'id'            => 7,
            'name'          => 'MediBot-01',
            'type'          => 'healthcare',
            'status'        => 'busy',
            'battery_level' => 95,
            'created_at'    => '2026-01-15 10:30:00',
        ]);

        $decoded = json_decode(json_encode($robot), true);

        $this->assertSame(
            ['id', 'name', 'type', 'status', 'battery_level', 'model_number',
             'serial_number', 'firmware_version', 'location', 'created_at'],
            array_keys($decoded)
        );
        $this->assertSame('2026-01-15 10:30:00', $decoded['created_at']);
        $this->assertSame(['lat' => null, 'lng' => null], $decoded['location']);
    }

    /** The __get magic method that exposed every protected property is gone. */
    public function testProtectedPropertiesAreNoLongerReadableFromOutside(): void
    {
        $robot = new WarehouseRobot(['name' => 'R-1']);

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line intentionally illegal access */
        $robot->serialNumber;
    }
}
