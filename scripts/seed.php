<?php

require_once __DIR__ . '/../src/Database.php';

use App\Database;

class Seeder {
    private $pdo;
    private $faker; // We'll simulate faker with helper methods

    public function __construct() {
        $db = new Database();
        $this->pdo = $db->getConnection();
    }

    public function run() {
        echo "Starting Database Seed...\n";
        
        $this->cleanDatabase();
        
        $deptIds = $this->seedDepartments();
        $roleIds = $this->seedRoles();
        $userIds = $this->seedUsers($deptIds, $roleIds);
        $arenaIds = $this->seedArenas();
        $capIds = $this->seedCapabilities();
        $taskIds = $this->seedTasks($capIds);
        
        $this->seedRobots(150, $deptIds, $arenaIds, $capIds);
        
        echo "Database Seed Completed Successfully!\n";
    }

    private function cleanDatabase() {
        echo "Cleaning old data...\n";
        $tables = [
            'audit_logs', 'maintenance_logs', 'schedules', 'robot_departments', 
            'robot_arenas', 'robot_capabilities', 'robots', 'user_roles', 
            'users', 'tasks', 'capabilities', 'arenas', 'roles', 'departments', 'firmware_updates'
        ];
        
        foreach ($tables as $table) {
            $this->pdo->exec("TRUNCATE TABLE $table CASCADE");
        }
    }

    private function seedDepartments() {
        echo "Seeding Departments...\n";
        $depts = [
            ['Logistics', 'B1'], ['Surgery', 'A2'], ['R&D', 'C1'], 
            ['Security', 'G1'], ['Maintenance', 'B2']
        ];
        $ids = [];
        $stmt = $this->pdo->prepare("INSERT INTO departments (name, building_code) VALUES (?, ?) RETURNING id");
        foreach ($depts as $d) {
            $stmt->execute($d);
            $ids[] = $stmt->fetchColumn();
        }
        return $ids;
    }

    private function seedRoles() {
        echo "Seeding Roles...\n";
        // Use 1/0 instead of true/false to avoid PDO empty string conversion issues
        $roles = [
            ['Admin', 1, 1], ['Technician', 0, 1], 
            ['Operator', 1, 0], ['Researcher', 1, 0]
        ];
        $ids = [];
        $stmt = $this->pdo->prepare("INSERT INTO roles (name, can_schedule, can_maintain) VALUES (?, ?, ?) RETURNING id");
        foreach ($roles as $r) {
            $stmt->execute($r);
            $ids[] = $stmt->fetchColumn();
        }
        return $ids;
    }

    private function seedUsers($deptIds, $roleIds) {
        echo "Seeding Users...\n";
        $ids = [];
        $stmt = $this->pdo->prepare("INSERT INTO users (username, email, password_hash, department_id) VALUES (?, ?, ?, ?) RETURNING id");
        $roleStmt = $this->pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");

        for ($i = 1; $i <= 20; $i++) {
            $deptId = $deptIds[array_rand($deptIds)];
            $username = "user_$i";
            $email = "user_$i@example.com";
            $pass = password_hash('password', PASSWORD_DEFAULT);
            
            $stmt->execute([$username, $email, $pass, $deptId]);
            $userId = $stmt->fetchColumn();
            $ids[] = $userId;

            // Assign random role
            $roleId = $roleIds[array_rand($roleIds)];
            $roleStmt->execute([$userId, $roleId]);
        }
        return $ids;
    }

    private function seedArenas() {
        echo "Seeding Arenas...\n";
        $arenas = [
            ['Main Warehouse', 'Indoor'], ['Loading Dock A', 'Outdoor'], 
            ['ICU Ward 3', 'Sterile'], ['Chem Lab 1', 'Hazardous'],
            ['Perimeter Fence North', 'Outdoor'], ['Server Room', 'Indoor']
        ];
        $ids = [];
        $stmt = $this->pdo->prepare("INSERT INTO arenas (name, type) VALUES (?, ?) RETURNING id");
        foreach ($arenas as $a) {
            $stmt->execute($a);
            $ids[] = $stmt->fetchColumn();
        }
        return $ids;
    }

    private function seedCapabilities() {
        echo "Seeding Capabilities...\n";
        $caps = [
            'Heavy Lifting', 'Precision Surgery', 'Night Vision', 'Hazardous Material Handling',
            'High Speed Data Link', 'Flight', 'Submersible', 'Voice Interaction'
        ];
        $ids = [];
        $stmt = $this->pdo->prepare("INSERT INTO capabilities (name) VALUES (?) RETURNING id");
        foreach ($caps as $c) {
            $stmt->execute([$c]);
            $ids[] = $stmt->fetchColumn();
        }
        return $ids;
    }

    private function seedTasks($capIds) {
        echo "Seeding Tasks...\n";
        $tasks = [
            ['Move Pallet', 1], ['Appendectomy', 2], ['Night Patrol', 3], 
            ['Clean Spill', 4], ['Data Sync', 5]
        ];
        $ids = [];
        $stmt = $this->pdo->prepare("INSERT INTO tasks (title, required_capability_id) VALUES (?, ?) RETURNING id");
        foreach ($tasks as $t) {
            // Map index to actual ID
            $capIndex = $t[1] - 1; 
            $capId = isset($capIds[$capIndex]) ? $capIds[$capIndex] : $capIds[0];
            
            $stmt->execute([$t[0], $capId]);
            $ids[] = $stmt->fetchColumn();
        }
        return $ids;
    }

    private function seedRobots($count, $deptIds, $arenaIds, $capIds) {
        echo "Seeding $count Robots...\n";
        
        $types = ['healthcare', 'warehouse', 'military', 'research', 'security'];
        $statuses = ['idle', 'busy', 'maintenance', 'error', 'charging'];
        $prefixes = ['X-', 'R-', 'Bot-', 'Unit-', 'Omega-'];
        
        $stmt = $this->pdo->prepare("
            INSERT INTO robots (
                name, type, status, battery_level, model_number, serial_number, 
                firmware_version, current_location_lat, current_location_lng, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id
        ");

        $deptStmt = $this->pdo->prepare("INSERT INTO robot_departments (robot_id, department_id) VALUES (?, ?)");
        $arenaStmt = $this->pdo->prepare("INSERT INTO robot_arenas (robot_id, arena_id) VALUES (?, ?)");
        $capStmt = $this->pdo->prepare("INSERT INTO robot_capabilities (robot_id, capability_id) VALUES (?, ?)");

        for ($i = 0; $i < $count; $i++) {
            $type = $types[array_rand($types)];
            $prefix = $prefixes[array_rand($prefixes)];
            $name = $prefix . rand(100, 9999);
            $status = $statuses[array_rand($statuses)];
            $battery = rand(5, 100);
            $model = "Mod-" . rand(1, 10) . strtoupper(substr($type, 0, 3));
            $serial = uniqid('SN-');
            $firmware = "v" . rand(1, 5) . "." . rand(0, 9);
            $lat = 40.7128 + (rand(-100, 100) / 1000);
            $lng = -74.0060 + (rand(-100, 100) / 1000);
            $created = date('Y-m-d H:i:s', strtotime("-" . rand(1, 365) . " days"));

            $stmt->execute([$name, $type, $status, $battery, $model, $serial, $firmware, $lat, $lng, $created]);
            $robotId = $stmt->fetchColumn();

            // Assign 1-2 Departments
            $d = $deptIds[array_rand($deptIds)];
            $deptStmt->execute([$robotId, $d]);

            // Assign 1-3 Arenas
            $numArenas = rand(1, 3);
            $shuffledArenas = $arenaIds;
            shuffle($shuffledArenas);
            for ($j=0; $j<$numArenas; $j++) {
                $arenaStmt->execute([$robotId, $shuffledArenas[$j]]);
            }

            // Assign 1-3 Capabilities
            $numCaps = rand(1, 3);
            $shuffledCaps = $capIds;
            shuffle($shuffledCaps);
            for ($k=0; $k<$numCaps; $k++) {
                $capStmt->execute([$robotId, $shuffledCaps[$k]]);
            }
        }
    }
}

// Run the seeder
$seeder = new Seeder();
$seeder->run();
