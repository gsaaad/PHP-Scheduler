<?php

declare(strict_types=1);

namespace App\Models;

enum RobotStatus: string
{
    case Idle        = 'idle';
    case Busy        = 'busy';
    case Maintenance = 'maintenance';
    case Error       = 'error';
    case Charging    = 'charging';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /**
     * States in which a robot cannot accept any booking, regardless of when.
     * Note this deliberately excludes Busy: a robot working right now is still
     * a valid target for a future window -- time conflicts are caught by the
     * overlap check in Schedule::scheduleTask() instead.
     *
     * @return list<self>
     */
    public static function unavailable(): array
    {
        return [self::Maintenance, self::Error];
    }

    public function isBookable(): bool
    {
        return !in_array($this, self::unavailable(), true);
    }
}
