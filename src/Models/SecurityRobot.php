<?php

declare(strict_types=1);

namespace App\Models;

class SecurityRobot extends BaseRobot
{
    public function performTask(string $taskName): bool
    {
        // Security specific logic
        error_log("Security Robot {$this->name} is patrolling: {$taskName}");
        return true;
    }
}
