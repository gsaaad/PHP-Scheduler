<?php

declare(strict_types=1);

namespace App\Models;

use App\Interfaces\RobotInterface;
use App\Traits\Timestampable;
use JsonSerializable;

abstract class BaseRobot implements RobotInterface, JsonSerializable
{
    use Timestampable;

    protected ?int $id;
    protected string $name;
    protected string $type;
    protected string $status;
    protected int $batteryLevel;
    protected ?string $modelNumber;
    protected ?string $serialNumber;
    protected ?string $firmwareVersion;
    protected ?float $currentLocationLat;
    protected ?float $currentLocationLng;

    public function __construct(array $data)
    {
        // Casts matter: PDO returns every pgsql column as a string, and the
        // typed properties above would otherwise reject them.
        $this->id              = isset($data['id']) ? (int) $data['id'] : null;
        $this->name            = (string) ($data['name'] ?? 'Unknown');
        $this->type            = (string) ($data['type'] ?? 'generic');
        $this->status          = (string) ($data['status'] ?? 'idle');
        $this->batteryLevel    = (int) ($data['battery_level'] ?? 100);
        $this->modelNumber     = isset($data['model_number']) ? (string) $data['model_number'] : null;
        $this->serialNumber    = isset($data['serial_number']) ? (string) $data['serial_number'] : null;
        $this->firmwareVersion = isset($data['firmware_version']) ? (string) $data['firmware_version'] : null;

        $this->currentLocationLat = isset($data['current_location_lat'])
            ? (float) $data['current_location_lat'] : null;
        $this->currentLocationLng = isset($data['current_location_lng'])
            ? (float) $data['current_location_lng'] : null;

        $this->setCreatedAt($data['created_at'] ?? new \DateTimeImmutable());
    }

    public function jsonSerialize(): mixed
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'type'             => $this->type,
            'status'           => $this->status,
            'battery_level'    => $this->batteryLevel,
            'model_number'     => $this->modelNumber,
            'serial_number'    => $this->serialNumber,
            'firmware_version' => $this->firmwareVersion,
            'location'         => [
                'lat' => $this->currentLocationLat,
                'lng' => $this->currentLocationLng,
            ],
            'created_at'       => $this->getFormattedTimestamp(),
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getBatteryLevel(): int
    {
        return $this->batteryLevel;
    }

    public function getModelNumber(): ?string
    {
        return $this->modelNumber;
    }

    public function getSerialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function getFirmwareVersion(): ?string
    {
        return $this->firmwareVersion;
    }

    /** @return array{lat: float|null, lng: float|null} */
    public function getLocation(): array
    {
        return ['lat' => $this->currentLocationLat, 'lng' => $this->currentLocationLng];
    }

    // Abstract method to be implemented by specific robot types
    abstract public function performTask(string $taskName): bool;
}
