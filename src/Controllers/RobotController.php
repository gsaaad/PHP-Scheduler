<?php

namespace App\Controllers;

use App\Models\Robot;
use PDO;

class RobotController {
    private $robotModel;

    public function __construct(PDO $db) {
        $this->robotModel = new Robot($db);
    }

    public function index() {
        $robots = $this->robotModel->getAll();
        header('Content-Type: application/json');
        echo json_encode($robots);
    }

    public function store() {
        $data = json_decode(file_get_contents('php://input'), true);
        if ($this->robotModel->create($data)) {
            http_response_code(201);
            echo json_encode(['message' => 'Robot created successfully']);
        } else {
            http_response_code(400);
            echo json_encode(['message' => 'Failed to create robot']);
        }
    }
}
