<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\ValidationException;
use App\Factories\RobotFactory;
use App\Models\RobotStatus;
use DateTimeImmutable;

/**
 * Rejects bad input before it reaches PDO. Without this, a malformed body made
 * json_decode() return null, which became a NOT NULL violation, which surfaced
 * as an uncaught PDOException and a 500 with a stack trace.
 *
 * Every method returns a normalised array on success or throws with a
 * field-keyed error map.
 */
class Validator
{
    public const MAX_NAME_LENGTH  = 100;
    public const MAX_TITLE_LENGTH = 255;
    public const MAX_DURATION     = 1440; // minutes (24h)
    public const MAX_PRIORITY     = 5;

    /**
     * @return array{name: string, type: string, battery_level: int}
     */
    public static function robot(mixed $data): array
    {
        $data   = self::asArray($data);
        $errors = [];

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (self::length($name) > self::MAX_NAME_LENGTH) {
            $errors['name'] = 'Name must be at most ' . self::MAX_NAME_LENGTH . ' characters.';
        }

        $known = RobotFactory::knownTypes();
        $type  = strtolower(trim((string) ($data['type'] ?? '')));
        if ($type === '') {
            $errors['type'] = 'Type is required.';
        } elseif (!in_array($type, $known, true)) {
            $errors['type'] = 'Type must be one of: ' . implode(', ', $known) . '.';
        }

        $battery = $data['battery_level'] ?? 100;
        if (!self::isIntegerish($battery)) {
            $errors['battery_level'] = 'Battery level must be an integer.';
        } elseif ((int) $battery < 0 || (int) $battery > 100) {
            // Mirrors the CHECK constraint on robots.battery_level
            $errors['battery_level'] = 'Battery level must be between 0 and 100.';
        }

        self::throwIf($errors);

        return ['name' => $name, 'type' => $type, 'battery_level' => (int) $battery];
    }

    /** @return array{status: string} */
    public static function robotStatus(mixed $data): array
    {
        $data   = self::asArray($data);
        $status = strtolower(trim((string) ($data['status'] ?? '')));

        if ($status === '') {
            self::throwIf(['status' => 'Status is required.']);
        }
        if (RobotStatus::tryFrom($status) === null) {
            self::throwIf(['status' => 'Status must be one of: ' . implode(', ', RobotStatus::values()) . '.']);
        }

        return ['status' => $status];
    }

    /**
     * @return array{title: string, description: string|null, priority: int, estimated_duration: int}
     */
    public static function task(mixed $data): array
    {
        $data   = self::asArray($data);
        $errors = [];

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $errors['title'] = 'Title is required.';
        } elseif (self::length($title) > self::MAX_TITLE_LENGTH) {
            $errors['title'] = 'Title must be at most ' . self::MAX_TITLE_LENGTH . ' characters.';
        }

        $priority = $data['priority'] ?? 1;
        if (!self::isIntegerish($priority)) {
            $errors['priority'] = 'Priority must be an integer.';
        } elseif ((int) $priority < 1 || (int) $priority > self::MAX_PRIORITY) {
            $errors['priority'] = 'Priority must be between 1 and ' . self::MAX_PRIORITY . '.';
        }

        // Accept "duration" as an alias -- the original Task::create() used it.
        $duration = $data['estimated_duration'] ?? $data['duration'] ?? 30;
        if (!self::isIntegerish($duration)) {
            $errors['estimated_duration'] = 'Estimated duration must be an integer.';
        } elseif ((int) $duration < 1 || (int) $duration > self::MAX_DURATION) {
            $errors['estimated_duration'] = 'Estimated duration must be between 1 and ' . self::MAX_DURATION . ' minutes.';
        }

        $description = isset($data['description']) ? trim((string) $data['description']) : null;

        self::throwIf($errors);

        return [
            'title'              => $title,
            'description'        => ($description === '' ? null : $description),
            'priority'           => (int) $priority,
            'estimated_duration' => (int) $duration,
        ];
    }

    /**
     * @return array{robot_id: int, task_id: int, start_time: DateTimeImmutable}
     */
    public static function schedule(mixed $data): array
    {
        $data   = self::asArray($data);
        $errors = [];

        foreach (['robot_id', 'task_id'] as $field) {
            $value = $data[$field] ?? null;
            if ($value === null || !self::isIntegerish($value) || (int) $value < 1) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' must be a positive integer.';
            }
        }

        $startTime = null;
        $raw       = trim((string) ($data['start_time'] ?? ''));
        if ($raw === '') {
            $errors['start_time'] = 'Start time is required.';
        } else {
            try {
                $startTime = new DateTimeImmutable($raw);
            } catch (\Exception) {
                $errors['start_time'] = 'Start time must be a valid datetime (e.g. 2026-08-04 09:00:00).';
            }
        }

        self::throwIf($errors);

        return [
            'robot_id'   => (int) $data['robot_id'],
            'task_id'    => (int) $data['task_id'],
            'start_time' => $startTime,
        ];
    }

    /**
     * View filters for the robot list. Junk is dropped rather than rejected --
     * these narrow a result set and never widen access.
     *
     * @param array<string, mixed> $query
     * @return array{arena_id?: int, status?: string, type?: string}
     */
    public static function robotFilters(array $query): array
    {
        $filters = [];

        if (isset($query['arena_id']) && self::isIntegerish($query['arena_id']) && (int) $query['arena_id'] > 0) {
            $filters['arena_id'] = (int) $query['arena_id'];
        }

        $status = isset($query['status']) ? strtolower(trim((string) $query['status'])) : '';
        if ($status !== '' && RobotStatus::tryFrom($status) !== null) {
            $filters['status'] = $status;
        }

        $type = isset($query['type']) ? strtolower(trim((string) $query['type'])) : '';
        if ($type !== '' && in_array($type, RobotFactory::knownTypes(), true)) {
            $filters['type'] = $type;
        }

        return $filters;
    }

    /**
     * @return array{description: string, kind: string, cost: ?float}
     */
    public static function maintenance(mixed $data): array
    {
        $data   = self::asArray($data);
        $errors = [];

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            $errors['description'] = 'A description of the work is required.';
        }

        $allowedKinds = ['repair', 'inspection', 'calibration', 'upgrade', 'cleaning'];
        $kind         = strtolower(trim((string) ($data['kind'] ?? 'repair')));
        if (!in_array($kind, $allowedKinds, true)) {
            $errors['kind'] = 'Kind must be one of: ' . implode(', ', $allowedKinds) . '.';
        }

        $cost = $data['cost'] ?? null;
        if ($cost !== null && (!is_numeric($cost) || (float) $cost < 0)) {
            $errors['cost'] = 'Cost must be a non-negative number.';
        }

        self::throwIf($errors);

        return [
            'description' => $description,
            'kind'        => $kind,
            'cost'        => $cost === null ? null : (float) $cost,
        ];
    }

    /**
     * @return array{version: string, description: ?string}
     */
    public static function firmware(mixed $data): array
    {
        $data   = self::asArray($data);
        $errors = [];

        $version = trim((string) ($data['version'] ?? ''));
        if ($version === '') {
            $errors['version'] = 'Version is required.';
        } elseif (self::length($version) > 20) {
            $errors['version'] = 'Version must be at most 20 characters.';
        }

        $description = isset($data['description']) ? trim((string) $data['description']) : null;

        self::throwIf($errors);

        return ['version' => $version, 'description' => $description === '' ? null : $description];
    }

    /**
     * @return array{limit: int, offset: int}
     */
    public static function pagination(array $query, int $defaultLimit = 50, int $maxLimit = 200): array
    {
        $limit = $query['limit'] ?? $defaultLimit;
        $limit = self::isIntegerish($limit) ? (int) $limit : $defaultLimit;
        $limit = max(1, min($maxLimit, $limit));

        $offset = $query['offset'] ?? 0;
        $offset = self::isIntegerish($offset) ? (int) $offset : 0;
        $offset = max(0, $offset);

        return ['limit' => $limit, 'offset' => $offset];
    }

    private static function asArray(mixed $data): array
    {
        // json_decode() returns null for a malformed or empty body
        if (!is_array($data)) {
            throw new ValidationException(['body' => 'Request body must be a JSON object.']);
        }
        return $data;
    }

    /**
     * Character count, not byte count -- the columns these guard are VARCHAR(n),
     * which Postgres measures in characters. mbstring is not universally
     * compiled in (it is absent from some stock Windows builds), so fall back to
     * a PCRE count rather than making the validator fatal there. strlen() is not
     * an option: it would reject a legal 60-character multibyte name.
     */
    private static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $count = preg_match_all('/./us', $value);

        return $count === false ? strlen($value) : $count;
    }

    private static function isIntegerish(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }
        if (is_bool($value) || is_array($value) || $value === null) {
            return false;
        }
        return is_string($value) || is_float($value)
            ? preg_match('/^-?\d+$/', (string) $value) === 1
            : false;
    }

    /** @param array<string, string> $errors */
    private static function throwIf(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }
}
