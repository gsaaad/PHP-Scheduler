<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\WarehouseRobot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TimestampableTest extends TestCase
{
    public function testRoundTripsAValidTimestamp(): void
    {
        $robot = new WarehouseRobot(['created_at' => '2026-01-15 10:30:00']);

        $this->assertSame('2026-01-15 10:30:00', $robot->getFormattedTimestamp());
    }

    /**
     * The old implementation was date('Y-m-d H:i:s', strtotime($x)), which
     * silently returned 1970-01-01 for anything unparseable.
     */
    public function testUnparseableTimestampThrowsInsteadOfDegradingToEpoch(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WarehouseRobot(['created_at' => 'not-a-date']);
    }

    public function testMissingTimestampDefaultsToNow(): void
    {
        $robot = new WarehouseRobot(['name' => 'R-1']);

        $this->assertNotNull($robot->getCreatedAt());
        $this->assertSame(
            (new \DateTimeImmutable())->format('Y-m-d'),
            $robot->getCreatedAt()->format('Y-m-d')
        );
    }
}
