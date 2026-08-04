<?php

namespace App\Models;

use App\Interfaces\RobotInterface;
use App\Traits\Timestampable;
use JsonSerializable;

abstract class BaseRobot implements RobotInterface, JsonSerializable {
    use Timestampable;

    protected $id;
    protected $name;
    protected $type;
    protected $status;
    protected $batteryLevel;
    protected $modelNumber;
    protected $serialNumber;
    protected $firmwareVersion;
    protected $currentLocationLat;
    protected $currentLocationLng;

    public function __construct(array $data) {
        $this->id = $data['id'] ?? null;
        $this->name = $data['name'] ?? 'Unknown';
        $this->type = $data['type'] ?? 'Generic';
        $this->status = $data['status'] ?? 'idle';
        $this->batteryLevel = $data['battery_level'] ?? 100;
        $this->modelNumber = $data['model_number'] ?? null;
        $this->serialNumber = $data['serial_number'] ?? null;
        $this->firmwareVersion = $data['firmware_version'] ?? null;
        $this->currentLocationLat = $data['current_location_lat'] ?? null;
        $this->currentLocationLng = $data['current_location_lng'] ?? null;
        $this->setCreatedAt($data['created_at'] ?? date('Y-m-d H:i:s'));
    }

    public function jsonSerialize(): mixed {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'status' => $this->status,
            'battery_level' => $this->batteryLevel,
            'model_number' => $this->modelNumber ?? null,
            'serial_number' => $this->serialNumber ?? null,
            'firmware_version' => $this->firmwareVersion ?? null,
            'location' => [
                'lat' => $this->currentLocationLat ?? null,
                'lng' => $this->currentLocationLng ?? null
            ],
            'created_at' => $this->getFormattedTimestamp()
        ];
    }

    public function getStatus(): string {
        return $this->status;
    }

    public function getBatteryLevel(): int {
        return $this->batteryLevel;
    }

    public function getName(): string {
        return $this->name;
    }

    // Abstract method to be implemented by specific robot types
    abstract public function performTask(string $taskName): bool;

    // Magic method to handle dynamic property access (Read-only)
    public function __get($name) {
        if (property_exists($this, $name)) {
            return $this->$name;
        }
        return null;
    }
}
