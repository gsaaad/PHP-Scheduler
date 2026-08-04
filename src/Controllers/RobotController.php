<?php

namespace App\Controllers;

use App\Models\RobotRepository;
use PDO;

class RobotController {
    private $robotRepo;

    public function __construct(PDO $db) {
        $this->robotRepo = new RobotRepository($db);
    }

    public function index() {
        $robots = $this->robotRepo->getAll();
        header('Content-Type: application/json');
        echo json_encode($robots);
    }

    public function store() {
        $data = json_decode(file_get_contents('php://input'), true);
        if ($this->robotRepo->create($data)) {
            http_response_code(201);
            echo json_encode(['message' => 'Robot created successfully']);
        } else {
            http_response_code(400);
            echo json_encode(['message' => 'Failed to create robot']);
        }
    }
}
