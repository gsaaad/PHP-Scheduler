<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Database;

class Seeder
{
    private $pdo;
    private $faker; // We'll simulate faker with helper methods

    public function __construct()
    {
        $db = new Database();
        $this->pdo = $db->getConnection();
    }

    public function run()
    {
        echo "Starting Database Seed...\n";

        $this->cleanDatabase();

        $deptIds = $this->seedDepartments();
        $roleIds = $this->seedRoles();
        $userIds = $this->seedUsers($deptIds, $roleIds);
        $arenaIds = $this->seedArenas();
        $capIds = $this->seedCapabilities();
        $taskIds = $this->seedTasks($capIds);

        $this->seedRobots(150, $deptIds, $arenaIds, $capIds);
        $this->seedAccessRules($deptIds, $arenaIds, $capIds);

        echo "Database Seed Completed Successfully!\n";
        echo "\nSign in with any of:\n";
        echo "  admin / password      (fleet administrator, unrestricted)\n";
        echo "  marine_lead / password (Marine Robotics -- walks AND swims, or floats)\n";
        echo "  bio_lead / password    (Biology -- biology-tagged robots only)\n";
        echo "  chem_lead / password   (Chemistry -- Chem Lab 1 arena only)\n";
    }

    private function cleanDatabase()
    {
        echo "Cleaning old data...\n";
        $tables = [
            'audit_logs',
            'robot_firmware_updates',
            'maintenance_logs',
            'access_rule_criteria',
            'access_rules',
            'api_tokens',
            'sessions',
            'schedules',
            'robot_departments',
            'robot_arenas',
            'robot_capabilities',
            'robots',
            'user_roles',
            'users',
            'tasks',
            'capabilities',
            'arenas',
            'roles',
            'departments',
            'firmware_updates'
        ];

        foreach ($tables as $table) {
            // RESTART IDENTITY matters: plain TRUNCATE leaves SERIAL sequences
            // where they were, so each re-seed produced ever-climbing ids and
            // the seed was not reproducible run to run.
            $this->pdo->exec("TRUNCATE TABLE $table RESTART IDENTITY CASCADE");
        }
    }

    private function seedDepartments()
    {
        echo "Seeding Departments...\n";
        // Keyed so access rules can refer to a department without index maths.
        $depts = [
            ['Logistics', 'B1'],
            ['Surgery', 'A2'],
            ['R&D', 'C1'],
            ['Security', 'G1'],
            ['Maintenance', 'B2'],
            ['Marine Robotics', 'M1'],
            ['Biology', 'L3'],
            ['Chemistry', 'L4'],
        ];
        $ids = [];
        $stmt = $this->pdo->prepare("INSERT INTO departments (name, building_code) VALUES (?, ?) RETURNING id");
        foreach ($depts as $d) {
            $stmt->execute($d);
            $ids[] = $stmt->fetchColumn();
        }
        return $ids;
    }

    private function seedRoles()
    {
        echo "Seeding Roles...\n";
        // Use 1/0 instead of true/false to avoid PDO empty string conversion issues
        // name, can_schedule, can_maintain, is_admin
        $roles = [
            ['Admin', 1, 1, 1],
            ['Technician', 0, 1, 0],
            ['Operator', 1, 0, 0],
            ['Researcher', 1, 0, 0]
        ];
        $ids = [];
        $stmt = $this->pdo->prepare(
            "INSERT INTO roles (name, can_schedule, can_maintain, is_admin) VALUES (?, ?, ?, ?) RETURNING id"
        );
        foreach ($roles as $r) {
            $stmt->execute($r);
            $ids[] = $stmt->fetchColumn();
        }
        return $ids;
    }

    private function seedUsers($deptIds, $roleIds)
    {
        echo "Seeding Users...\n";
        $ids = [];
        $stmt = $this->pdo->prepare("INSERT INTO users (username, email, password_hash, department_id) VALUES (?, ?, ?, ?) RETURNING id");
        $roleStmt = $this->pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");

        // Named accounts, one per access scenario, so the model can be exercised
        // without hunting for a user_N that happens to sit in the right lab.
        // [username, department index, role index]
        //   roles: 0=Admin 1=Technician 2=Operator 3=Researcher
        //   depts: 5=Marine Robotics 6=Biology 7=Chemistry
        $named = [
            ['admin',       null, 0],
            ['marine_lead', 5,    2],
            ['bio_lead',    6,    2],
            ['chem_lead',   7,    2],
            ['tech_lead',   4,    1],
        ];

        foreach ($named as [$username, $deptIndex, $roleIndex]) {
            $deptId = $deptIndex === null ? null : $deptIds[$deptIndex];
            $stmt->execute([
                $username,
                "$username@example.com",
                password_hash('password', PASSWORD_DEFAULT),
                $deptId,
            ]);
            $userId = $stmt->fetchColumn();
            $ids[] = $userId;
            $roleStmt->execute([$userId, $roleIds[$roleIndex]]);
        }

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

    private function seedArenas()
    {
        echo "Seeding Arenas...\n";
        $arenas = [
            ['Main Warehouse', 'Indoor'],
            ['Loading Dock A', 'Outdoor'],
            ['ICU Ward 3', 'Sterile'],
            ['Chem Lab 1', 'Hazardous'],
            ['Perimeter Fence North', 'Outdoor'],
            ['Server Room', 'Indoor']
        ];
        $ids = [];
        $stmt = $this->pdo->prepare("INSERT INTO arenas (name, type) VALUES (?, ?) RETURNING id");
        foreach ($arenas as $a) {
            $stmt->execute($a);
            $ids[] = $stmt->fetchColumn();
        }
        return $ids;
    }

    private function seedCapabilities()
    {
        echo "Seeding Capabilities...\n";
        // Indices matter: seedAccessRules() refers to these positions.
        // 8 and 9 exist so the locomotion-based access rules (walk AND swim,
        // or float) have something real to match.
        $caps = [
            'Heavy Lifting',               // 0
            'Precision Surgery',           // 1
            'Night Vision',                // 2
            'Hazardous Material Handling', // 3
            'High Speed Data Link',        // 4
            'Flight',                      // 5
            'Submersible',                 // 6  "swims"
            'Voice Interaction',           // 7
            'Terrain Walking',             // 8  "walks"
            'Surface Flotation',           // 9  "floats"
        ];
        $ids = [];
        $stmt = $this->pdo->prepare("INSERT INTO capabilities (name) VALUES (?) RETURNING id");
        foreach ($caps as $c) {
            $stmt->execute([$c]);
            $ids[] = $stmt->fetchColumn();
        }
        return $ids;
    }

    private function seedTasks($capIds)
    {
        echo "Seeding Tasks...\n";
        // title, capability index (1-based), description, priority, duration (min), min battery %
        // Longer jobs demand more headroom -- an 180-minute patrol should not
        // start on a robot at 25%.
        $tasks = [
            ['Move Pallet', 1, 'Relocate a loaded pallet between bays.', 2, 45, 30],
            ['Appendectomy', 2, 'Assist the surgical team in theatre.', 5, 120, 70],
            ['Night Patrol', 3, 'Sweep the perimeter after hours.', 3, 180, 80],
            ['Clean Spill', 4, 'Contain and neutralise a chemical spill.', 4, 60, 40],
            ['Data Sync', 5, 'Push telemetry to the central cluster.', 1, 15, 10]
        ];
        $ids = [];
        $stmt = $this->pdo->prepare("
            INSERT INTO tasks (title, description, priority, estimated_duration, min_battery_level, required_capability_id)
            VALUES (?, ?, ?, ?, ?, ?) RETURNING id
        ");
        foreach ($tasks as $t) {
            // Map index to actual ID
            $capIndex = $t[1] - 1;
            $capId = isset($capIds[$capIndex]) ? $capIds[$capIndex] : $capIds[0];

            $stmt->execute([$t[0], $t[2], $t[3], $t[4], $t[5], $capId]);
            $ids[] = $stmt->fetchColumn();
        }
        return $ids;
    }

    private function seedRobots($count, $deptIds, $arenaIds, $capIds)
    {
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
            for ($j = 0; $j < $numArenas; $j++) {
                $arenaStmt->execute([$robotId, $shuffledArenas[$j]]);
            }

            // Assign 1-3 Capabilities
            $numCaps = rand(1, 3);
            $shuffledCaps = $capIds;
            shuffle($shuffledCaps);
            for ($k = 0; $k < $numCaps; $k++) {
                $capStmt->execute([$robotId, $shuffledCaps[$k]]);
            }
        }
    }

    /**
     * Access rules, one set per lab, demonstrating each criterion kind.
     *
     * Semantics: a robot is reachable when ANY rule matches, and a rule matches
     * when ALL of its criteria hold. So "walks AND swims, or floats" is two
     * rules -- the first carrying two capability criteria, the second one.
     */
    private function seedAccessRules($deptIds, $arenaIds, $capIds)
    {
        echo "Seeding Access Rules...\n";

        $ruleStmt = $this->pdo->prepare(
            "INSERT INTO access_rules (department_id, name, description) VALUES (?, ?, ?) RETURNING id"
        );
        $critStmt = $this->pdo->prepare(
            "INSERT INTO access_rule_criteria (rule_id, kind, ref_id, ref_value) VALUES (?, ?, ?, ?)"
        );

        $addRule = function ($deptId, $name, $description, array $criteria) use ($ruleStmt, $critStmt) {
            $ruleStmt->execute([$deptId, $name, $description]);
            $ruleId = $ruleStmt->fetchColumn();
            foreach ($criteria as [$kind, $refId, $refValue]) {
                $critStmt->execute([$ruleId, $kind, $refId, $refValue]);
            }
        };

        // Marine Robotics: amphibious units (walk AND swim) OR anything that floats.
        $addRule(
            $deptIds[5],
            'Amphibious units',
            'Robots that can both traverse terrain and operate submerged.',
            [['capability', $capIds[8], null], ['capability', $capIds[6], null]]
        );
        $addRule(
            $deptIds[5],
            'Surface craft',
            'Anything that floats on the surface.',
            [['capability', $capIds[9], null]]
        );

        // Biology: only robots assigned to the Biology department.
        $addRule(
            $deptIds[6],
            'Biology fleet',
            'Robots assigned to the Biology department only.',
            [['department', $deptIds[6], null]]
        );

        // Chemistry: scoped to a physical lab, regardless of robot type.
        $addRule(
            $deptIds[7],
            'Chem Lab 1 floor access',
            'Every robot stationed in Chem Lab 1.',
            [['arena', $arenaIds[3], null]]
        );

        // Maintenance technicians: by robot type, across all labs.
        $addRule(
            $deptIds[4],
            'Research hardware',
            'All research-type robots.',
            [['type', null, 'research']]
        );
        $addRule(
            $deptIds[4],
            'Warehouse hardware',
            'All warehouse-type robots.',
            [['type', null, 'warehouse']]
        );
    }
}

// Run the seeder
$seeder = new Seeder();
$seeder->run();
