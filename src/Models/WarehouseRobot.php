<?php

declare(strict_types=1);

namespace App\Models;

class WarehouseRobot extends BaseRobot
{
    public function performTask(string $taskName): bool
    {
        // Warehouse specific logic
        error_log("Warehouse Robot {$this->name} is moving inventory: {$taskName}");
        return true;
    }
}
