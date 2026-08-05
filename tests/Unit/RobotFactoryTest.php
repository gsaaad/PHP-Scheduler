<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Factories\RobotFactory;
use App\Models\BaseRobot;
use App\Models\GenericRobot;
use App\Models\HealthcareRobot;
use App\Models\MilitaryRobot;
use App\Models\ResearchRobot;
use App\Models\SecurityRobot;
use App\Models\WarehouseRobot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RobotFactoryTest extends TestCase
{
    public static function seededTypeProvider(): array
    {
        // Exactly the five types scripts/seed.php emits
        return [
            'healthcare' => ['healthcare', HealthcareRobot::class],
            'warehouse'  => ['warehouse',  WarehouseRobot::class],
            'military'   => ['military',   MilitaryRobot::class],
            'research'   => ['research',   ResearchRobot::class],
            'security'   => ['security',   SecurityRobot::class],
        ];
    }

    #[DataProvider('seededTypeProvider')]
    public function testEverySeededTypeMapsToADedicatedClass(string $type, string $expected): void
    {
        $robot = RobotFactory::create(['name' => 'Bot-1', 'type' => $type]);

        $this->assertInstanceOf($expected, $robot);
    }

    public function testUnknownTypeFallsBackToNamedGenericRobotNotAnonymousClass(): void
    {
        $robot = RobotFactory::create(['name' => 'Bot-1', 'type' => 'agricultural']);

        $this->assertInstanceOf(GenericRobot::class, $robot);
        // The old anonymous-class fallback produced a name like "class@anonymous..."
        $this->assertStringNotContainsString('@anonymous', $robot::class);
    }

    public function testTypeMatchingIsCaseAndWhitespaceInsensitive(): void
    {
        $this->assertInstanceOf(
            HealthcareRobot::class,
            RobotFactory::create(['name' => 'Bot-1', 'type' => '  HealthCare '])
        );
    }

    public function testMissingTypeDoesNotWarnAndYieldsGenericRobot(): void
    {
        $robot = RobotFactory::create(['name' => 'Bot-1']);

        $this->assertInstanceOf(GenericRobot::class, $robot);
    }

    public function testKnownTypesMatchesTheFactoryMapping(): void
    {
        $known = RobotFactory::knownTypes();

        $this->assertSame(array_keys(self::seededTypeProvider()), $known);

        foreach ($known as $type) {
            $robot = RobotFactory::create(['name' => 'x', 'type' => $type]);
            $this->assertInstanceOf(BaseRobot::class, $robot);
            $this->assertNotInstanceOf(GenericRobot::class, $robot);
        }
    }
}
