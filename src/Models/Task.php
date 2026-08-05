<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\NotFoundException;
use PDO;

class Task
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAll(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM tasks ORDER BY priority DESC, id ASC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$limit, $offset]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    public function findOrFail(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? throw NotFoundException::task($id) : $row;
    }

    /**
     * @param array{title: string, description: ?string, priority: int, estimated_duration: int} $data
     *        validated by Http\Validator
     * @return array<string, mixed> the created row
     */
    public function create(array $data): array
    {
        // description / priority / estimated_duration exist on the table as of
        // the schema fix; this model previously wrote columns that were absent,
        // so every call failed with SQLSTATE 42703.
        $stmt = $this->db->prepare(
            'INSERT INTO tasks (title, description, priority, estimated_duration, required_capability_id)
             VALUES (:title, :description, :priority, :duration, :capability_id)
             RETURNING *'
        );
        $stmt->execute([
            'title'         => $data['title'],
            'description'   => $data['description'] ?? null,
            'priority'      => $data['priority'] ?? 1,
            'duration'      => $data['estimated_duration'] ?? 30,
            'capability_id' => $data['required_capability_id'] ?? null,
        ]);

        return $stmt->fetch();
    }
}
