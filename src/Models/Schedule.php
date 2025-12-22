<?php

namespace App\Models;

use PDO;

class Schedule {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function scheduleTask($robotId, $taskId, $startTime) {
        // Basic validation: check if robot is idle
        $stmt = $this->db->prepare("SELECT status FROM robots WHERE id = ?");
        $stmt->execute([$robotId]);
        $robot = $stmt->fetch();

        if ($robot['status'] !== 'idle') {
            throw new \Exception("Robot is currently busy or in maintenance.");
        }

        // Calculate end time (simplified: start + 1 hour)
        $endTime = date('Y-m-d H:i:s', strtotime($startTime . ' +1 hour'));

        $stmt = $this->db->prepare("INSERT INTO schedules (robot_id, task_id, start_time, end_time) VALUES (?, ?, ?, ?)");
        $stmt->execute([$robotId, $taskId, $startTime, $endTime]);

        // Update robot status
        $stmt = $this->db->prepare("UPDATE robots SET status = 'busy' WHERE id = ?");
        $stmt->execute([$robotId]);

        return true;
    }

    public function getFullSchedule() {
        $sql = "SELECT s.*, r.name as robot_name, t.title as task_title 
                FROM schedules s
                JOIN robots r ON s.robot_id = r.id
                JOIN tasks t ON s.task_id = t.id
                ORDER BY s.start_time ASC";
        return $this->db->query($sql)->fetchAll();
    }
}
