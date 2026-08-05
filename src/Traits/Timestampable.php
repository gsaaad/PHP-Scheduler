<?php

declare(strict_types=1);

namespace App\Traits;

use DateTimeImmutable;
use InvalidArgumentException;

trait Timestampable
{
    protected ?DateTimeImmutable $createdAt = null;

    /**
     * Accepts a datetime string or a DateTimeImmutable. Unparseable input throws
     * rather than silently degrading to 1970-01-01, which is what the old
     * date(..., strtotime($x)) round-trip did.
     */
    public function setCreatedAt(DateTimeImmutable|string|null $date): void
    {
        if ($date === null) {
            $this->createdAt = null;
            return;
        }

        if ($date instanceof DateTimeImmutable) {
            $this->createdAt = $date;
            return;
        }

        try {
            $this->createdAt = new DateTimeImmutable($date);
        } catch (\Exception $e) {
            throw new InvalidArgumentException("Unparseable timestamp: {$date}", 0, $e);
        }
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFormattedTimestamp(): ?string
    {
        return $this->createdAt?->format('Y-m-d H:i:s');
    }
}
