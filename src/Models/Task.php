<?php

namespace App\Models;

use PDO;

class Task {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getAll() {
        $stmt = $this->db->query("SELECT * FROM tasks ORDER BY priority DESC");
        return $stmt->fetchAll();
    }

    public function create($data) {
        $stmt = $this->db->prepare("INSERT INTO tasks (title, description, priority, estimated_duration) VALUES (:title, :description, :priority, :duration)");
        return $stmt->execute([
            'title' => $data['title'],
            'description' => $data['description'],
            'priority' => $data['priority'] ?? 1,
            'duration' => $data['duration'] ?? 30
        ]);
    }
}
