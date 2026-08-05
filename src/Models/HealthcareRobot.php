<?php

declare(strict_types=1);

namespace App\Models;

class HealthcareRobot extends BaseRobot
{
    public function performTask(string $taskName): bool
    {
        // Healthcare specific logic
        error_log("Healthcare Robot {$this->name} is performing medical task: {$taskName}");
        return true;
    }
}
