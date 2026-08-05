<?php

declare(strict_types=1);

namespace App\Models;

class MilitaryRobot extends BaseRobot
{
    public function performTask(string $taskName): bool
    {
        // Military specific logic
        error_log("Military Robot {$this->name} is executing field operation: {$taskName}");
        return true;
    }
}
