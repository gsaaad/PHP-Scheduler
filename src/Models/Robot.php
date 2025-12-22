<?php

namespace App\Models;

use PDO;

class Robot {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getAll() {
        $stmt = $this->db->query("SELECT * FROM robots");
        return $stmt->fetchAll();
    }

    public function find($id) {
        $stmt = $this->db->prepare("SELECT * FROM robots WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function create($data) {
        $stmt = $this->db->prepare("INSERT INTO robots (name, type, battery_level) VALUES (:name, :type, :battery_level)");
        return $stmt->execute([
            'name' => $data['name'],
            'type' => $data['type'],
            'battery_level' => $data['battery_level'] ?? 100
        ]);
    }

    public function updateStatus($id, $status) {
        $stmt = $this->db->prepare("UPDATE robots SET status = ? WHERE id = ?");
        return $stmt->execute([$status, $id]);
    }
}
