<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Fallback for robot types with no dedicated class.
 *
 * Replaces the anonymous class the factory used to return, which produced an
 * unusable get_class() and made unrecognised types impossible to diagnose.
 */
class GenericRobot extends BaseRobot
{
    public function performTask(string $taskName): bool
    {
        error_log("Generic Robot {$this->name} (type: {$this->type}) is performing: {$taskName}");
        return true;
    }
}
