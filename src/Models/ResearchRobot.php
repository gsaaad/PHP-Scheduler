<?php

declare(strict_types=1);

namespace App\Models;

class ResearchRobot extends BaseRobot
{
    public function performTask(string $taskName): bool
    {
        // Research specific logic
        error_log("Research Robot {$this->name} is running experiment: {$taskName}");
        return true;
    }
}
