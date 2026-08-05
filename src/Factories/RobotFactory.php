<?php

declare(strict_types=1);

namespace App\Factories;

use App\Models\BaseRobot;
use App\Models\GenericRobot;
use App\Models\HealthcareRobot;
use App\Models\MilitaryRobot;
use App\Models\ResearchRobot;
use App\Models\SecurityRobot;
use App\Models\WarehouseRobot;

class RobotFactory
{
    /**
     * Every type the seeder emits maps to a concrete class. Previously only
     * healthcare and warehouse were handled, so military/research/security --
     * three of the five seeded types -- fell through to an anonymous class.
     *
     * @var array<string, class-string<BaseRobot>>
     */
    private const TYPE_MAP = [
        'healthcare' => HealthcareRobot::class,
        'warehouse'  => WarehouseRobot::class,
        'military'   => MilitaryRobot::class,
        'research'   => ResearchRobot::class,
        'security'   => SecurityRobot::class,
    ];

    public static function create(array $data): BaseRobot
    {
        $type  = strtolower(trim((string) ($data['type'] ?? '')));
        $class = self::TYPE_MAP[$type] ?? GenericRobot::class;

        return new $class($data);
    }

    /**
     * The set of types the API accepts. Used by the validator so the allowed
     * list cannot drift from what the factory can actually build.
     *
     * @return list<string>
     */
    public static function knownTypes(): array
    {
        return array_keys(self::TYPE_MAP);
    }
}
