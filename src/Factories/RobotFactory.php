<?php

namespace App\Factories;

use App\Models\HealthcareRobot;
use App\Models\WarehouseRobot;
use App\Models\BaseRobot;
use Exception;

class RobotFactory {
    public static function create(array $data): BaseRobot {
        switch ($data['type']) {
            case 'healthcare':
                return new HealthcareRobot($data);
            case 'warehouse':
                return new WarehouseRobot($data);
            default:
                // Fallback or generic robot
                return new class($data) extends BaseRobot {
                    public function performTask(string $taskName): bool {
                        return true;
                    }
                };
        }
    }
}
