<?php

namespace App\Interfaces;

interface RobotInterface {
    public function performTask(string $taskName): bool;
    public function getStatus(): string;
    public function getBatteryLevel(): int;
}
