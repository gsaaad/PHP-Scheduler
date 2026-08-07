<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Database;

class Seeder
{
    private $pdo;
    private $faker; // We'll simulate faker with helper methods
    /** @var list<int> populated by seedArenas(), used to place charging docks */
    private $chargingArenaIds = [];

    /** Shared password for the named lab accounts; shown openly on the demo. */
    private string $demoPassword;
    /** Separate from the lab password: admin can register robots and upload media. */
    private string $adminPassword;
    /** The 20 filler accounts are off unless asked for -- see seedUsers(). */
    private bool $includeFiller;

    public function __construct()
    {
        $db = new Database();
        $this->pdo = $db->getConnection();

        $isProduction = getenv('APP_ENV') === 'production';

        $demo  = getenv('SEED_DEMO_PASSWORD') ?: '';
        $admin = getenv('SEED_ADMIN_PASSWORD') ?: '';

        // In production both must be supplied. Defaulting to a known string on a
        // public deploy is how a demo becomes someone else's server.
        if ($isProduction && ($demo === '' || $admin === '')) {
            fwrite(STDERR, "APP_ENV=production requires SEED_DEMO_PASSWORD and SEED_ADMIN_PASSWORD.\n");
            exit(1);
        }

        $this->demoPassword  = $demo !== '' ? $demo : 'password';
        $this->adminPassword = $admin !== '' ? $admin : $this->demoPassword;
        $this->includeFiller = getenv('SEED_INCLUDE_FILLER') === '1';
    }

    public function run()
    {
        echo "Starting Database Seed...\n";

        $this->cleanDatabase();
        $this->chargingArenaIds = [];

        $deptIds = $this->seedDepartments();
        $roleIds = $this->seedRoles();
        $userIds = $this->seedUsers($deptIds, $roleIds);
        $arenaIds = $this->seedArenas();
        $capIds = $this->seedCapabilities();
        $taskIds = $this->seedTasks($capIds);

        $this->seedRobots(150, $deptIds, $arenaIds, $capIds);
        $this->seedAccessRules($deptIds, $arenaIds, $capIds);

        echo "Database Seed Completed Successfully!\n";

        $shown = $this->demoPassword === 'password' ? 'password' : '<SEED_DEMO_PASSWORD>';
        echo "\nSign in with any of:\n";
        echo "  marine_lead / {$shown} (Marine Robotics -- walks AND swims, or floats)\n";
        echo "  bio_lead / {$shown}    (Biology -- biology-tagged robots only)\n";
        echo "  chem_lead / {$shown}   (Chemistry -- Chem Lab 1 arena only)\n";
        echo "  tech_lead / {$shown}   (Maintenance -- can maintain, cannot schedule)\n";
        echo "  admin -- fleet administrator, password from SEED_ADMIN_PASSWORD\n";
    }

    private function cleanDatabase()
    {
        // TRUNCATE across every table in the schema is not something to do by
        // accident: it takes users, sessions, issued tokens and the audit trail
        // with it. Convention alone kept this away from a live database.
        if (getenv('ALLOW_DESTRUCTIVE_SEED') !== '1') {
            fwrite(STDERR, "Refusing to seed: this TRUNCATEs every table, including users,\n");
            fwrite(STDERR, "sessions, api_tokens and audit_logs.\n\n");
            fwrite(STDERR, "Re-run with ALLOW_DESTRUCTIVE_SEED=1 if that is what you want.\n");
            exit(1);
        }

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
            // Added in 004_robot_city.sql and missed here, so charge sessions
            // accumulated across re-seeds and referenced robots that no longer
            // existed.
            'charge_sessions',
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
            // admin holds a separate password: it is the one account that can
            // register robots and upload media, so it does not share the
            // credential printed on the demo login page.
            $plain = $username === 'admin' ? $this->adminPassword : $this->demoPassword;
            $stmt->execute([
                $username,
                "$username@example.com",
                password_hash($plain, PASSWORD_DEFAULT),
                $deptId,
            ]);
            $userId = $stmt->fetchColumn();
            $ids[] = $userId;
            $roleStmt->execute([$userId, $roleIds[$roleIndex]]);
        }

        // Filler accounts are off by default. They were drawing a RANDOM role,
        // which meant several user_N accounts landed on Admin -- predictable
        // usernames with a shared password and full fleet rights. Useful for
        // local volume testing, never on a public deploy.
        if (!$this->includeFiller) {
            return $ids;
        }

        for ($i = 1; $i <= 20; $i++) {
            $deptId = $deptIds[array_rand($deptIds)];
            $username = "user_$i";
            $email = "user_$i@example.com";
            $pass = password_hash($this->demoPassword, PASSWORD_DEFAULT);

            $stmt->execute([$username, $email, $pass, $deptId]);
            $userId = $stmt->fetchColumn();
            $ids[] = $userId;

            // Never Admin: a filler account exists to add volume, not rights.
            $nonAdminRoleIds = array_slice($roleIds, 1); // index 0 is Admin
            $roleId = $nonAdminRoleIds[array_rand($nonAdminRoleIds)];
            $roleStmt->execute([$userId, $roleId]);
        }
        return $ids;
    }

    /**
     * RobotCity coordinate frame, matched to the illustrated map
     * (docs/robotcity-map-prompt.md and public/images/robotcity.png).
     *
     * Sites are authored as map percentages -- x left-to-right, y top-to-bottom
     * -- and converted to lat/lng here. Keeping the map as the source of truth
     * means a robot's dot lands on the building it is actually standing in.
     */
    private const LNG_WEST  = -74.0360;   // x = 0
    private const LNG_SPAN  = 0.06;       // ~5.0 km wide
    private const LAT_NORTH = 40.7328;    // y = 0
    private const LAT_SPAN  = 0.04;       // ~4.4 km tall

    private static function mapLng($xPct)
    {
        return self::LNG_WEST + ($xPct / 100) * self::LNG_SPAN;
    }

    private static function mapLat($yPct)
    {
        return self::LAT_NORTH - ($yPct / 100) * self::LAT_SPAN;
    }

    /**
     * RobotCity: five district sites per robot discipline, plus ten charging
     * stations spread across the map so no robot is far from a dock.
     *
     * Offsets are in degrees; at this latitude 0.001 deg is roughly 111 m
     * north-south and 84 m east-west, so the whole city spans a few kilometres.
     */
    private function seedArenas()
    {
        echo "Seeding RobotCity (25 district sites + 10 charging stations)...\n";

        // domain => [ [name, environment, code, dLat, dLng], ... ]
        // Authored as map percentages: [name, environment, code, x%, y%]
        $districts = [
            'healthcare' => [
                ['Pathology Annex',     'Indoor',    'HC-4', 14.7, 15.0],
                ['Mercy Wing 2',        'Sterile',   'HC-1', 32.0, 13.0],
                ['ICU Ward 3',          'Sterile',   'HC-2', 48.0, 14.0],
                ['Surgical Theatre B',  'Sterile',   'HC-3', 62.0, 14.0],
                ['Ambulance Bay North', 'Outdoor',   'HC-5', 81.0, 12.0],
            ],
            'military' => [
                ['Signals Bunker',      'Indoor',    'ML-5', 12.7, 30.0],
                ['Vehicle Depot 2',     'Indoor',    'ML-3', 20.0, 32.0],
                ['Armoury Bunker',      'Indoor',    'ML-1', 12.0, 51.0],
                ['Live Fire Range',     'Outdoor',   'ML-2', 15.0, 62.0],
                ['Forward Post West',   'Outdoor',   'ML-4',  7.0, 70.0],
            ],
            'research' => [
                ['Cryogenics Vault',    'Indoor',    'RS-2', 81.0, 27.0],
                ['Biocontainment C',    'Sterile',   'RS-4', 75.0, 42.0],
                ['Chem Lab 1',          'Hazardous', 'RS-1', 89.0, 44.0],
                ['Optics Bench 4',      'Indoor',    'RS-3', 75.0, 57.0],
                ['Test Range East',     'Outdoor',   'RS-5', 92.0, 65.0],
            ],
            'warehouse' => [
                ['Cold Storage 7',      'Indoor',    'WH-3', 19.0, 79.0],
                ['Palletising Floor',   'Indoor',    'WH-4', 32.0, 78.0],
                ['Main Warehouse',      'Indoor',    'WH-1', 50.0, 80.0],
                ['Loading Dock A',      'Outdoor',   'WH-2', 65.0, 80.0],
                ['Rail Transfer Yard',  'Outdoor',   'WH-5', 82.0, 79.0],
            ],
            'security' => [
                ['Perimeter Fence North', 'Outdoor', 'SC-1', 50.0,  3.0],
                ['Surveillance Hub',      'Indoor',  'SC-4', 36.0, 45.0],
                ['Server Room',           'Indoor',  'SC-2', 63.0, 48.0],
                ['Gatehouse East',        'Outdoor', 'SC-3', 96.0, 51.0],
                ['Perimeter Fence South', 'Outdoor', 'SC-5', 50.0, 96.0],
            ],
        ];

        // [name, code, x%, y%, bays]. The first four are drawn on the map; the
        // rest sit in open ground and render as overlay pins.
        $chargers = [
            ['Dock Golf',    'CH-07', 50.0, 44.0, 10],   // the illustrated hub
            ['Dock Alpha',   'CH-01', 70.0, 21.0, 6],
            ['Dock Bravo',   'CH-02', 83.0, 49.0, 6],
            ['Dock Charlie', 'CH-03', 78.0, 70.0, 8],
            ['Dock Delta',   'CH-04', 21.0, 21.0, 6],
            ['Dock Echo',    'CH-05', 90.0, 11.0, 4],
            ['Dock Foxtrot', 'CH-06',  9.0, 87.0, 4],
            ['Dock Hotel',   'CH-08', 50.0, 67.0, 4],
            ['Dock India',   'CH-09', 32.0, 57.0, 4],
            ['Dock Juliet',  'CH-10', 62.0, 36.0, 4],
        ];

        $stmt = $this->pdo->prepare(
            "INSERT INTO arenas (name, type, domain, code, latitude, longitude, radius_m, capacity)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id"
        );

        $ids = [];
        foreach ($districts as $domain => $sites) {
            foreach ($sites as [$name, $env, $code, $x, $y]) {
                $stmt->execute([
                    $name, $env, $domain, $code,
                    self::mapLat($y), self::mapLng($x),
                    200, null,
                ]);
                $ids[] = $stmt->fetchColumn();
            }
        }

        $this->chargingArenaIds = [];
        foreach ($chargers as [$name, $code, $x, $y, $capacity]) {
            $stmt->execute([
                $name, 'Indoor', 'charging', $code,
                self::mapLat($y), self::mapLng($x),
                120, $capacity,
            ]);
            $id = $stmt->fetchColumn();
            $ids[] = $id;
            $this->chargingArenaIds[] = $id;
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

        // Endurance varies by platform weight, not just by discipline: a heavy
        // lifter and a lightweight scout are different machines even inside the
        // same department.
        //
        //   class    => [min endurance, max endurance, return reserve]
        // The reserve is the trip back to a dock and is never schedulable. It is
        // 30 minutes across the board so the arithmetic is predictable
        // (4h30 endurance - 3h booked - 30m return = 1h for the next department);
        // the column is per-robot, so a slower platform can be tuned later.
        $dutyClasses = [
            'heavy'    => [240, 285, 30],  // 4 - 4h45
            'standard' => [300, 360, 30],  // 5 - 6 h
            'light'    => [390, 420, 30],  // 6h30 - 7 h
        ];

        // Weighting per discipline: warehouse and military skew heavy, security
        // and research skew light, healthcare sits in the middle.
        $classMix = [
            'warehouse'  => ['heavy', 'heavy', 'heavy', 'standard', 'light'],
            'military'   => ['heavy', 'heavy', 'standard', 'standard', 'light'],
            'healthcare' => ['heavy', 'standard', 'standard', 'standard', 'light'],
            'research'   => ['standard', 'standard', 'light', 'light', 'heavy'],
            'security'   => ['light', 'light', 'light', 'standard', 'heavy'],
        ];

        // Sites belonging to each discipline, so a robot is placed in its own
        // district rather than scattered at random across the city.
        $sitesByDomain = [];
        foreach ($this->pdo->query("SELECT id, domain, latitude, longitude FROM arenas WHERE latitude IS NOT NULL") as $row) {
            $sitesByDomain[$row['domain']][] = $row;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO robots (
                name, type, status, battery_level, model_number, serial_number,
                firmware_version, current_location_lat, current_location_lng, created_at,
                max_duty_minutes, duty_minutes_used, image_url, image_hover_url,
                duty_class, return_reserve_minutes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id
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
            $created = date('Y-m-d H:i:s', strtotime("-" . rand(1, 365) . " days"));

            // Place the robot near a site in its own district. About one in six
            // is dropped further out so "in transit" is a state the map and the
            // ping response actually exercise.
            $home = $sitesByDomain[$type][array_rand($sitesByDomain[$type])];
            $spread = (rand(1, 6) === 1) ? 0.0060 : 0.0012;
            $lat = (float) $home['latitude']  + (rand(-1000, 1000) / 1000) * $spread;
            $lng = (float) $home['longitude'] + (rand(-1000, 1000) / 1000) * $spread;

            $dutyClass = $classMix[$type][array_rand($classMix[$type])];
            [$minDuty, $maxDutyRange, $reserve] = $dutyClasses[$dutyClass];
            $maxDuty = rand($minDuty, $maxDutyRange);

            // Charging robots have spent everything bookable; others have
            // consumed part of their day.
            $schedulable = $maxDuty - $reserve;
            $dutyUsed = $status === 'charging'
                ? $schedulable
                : (rand(1, 3) === 1 ? rand(15, max(20, $schedulable - 30)) : 0);

            // Images are supplied per type; the UI falls back to a generated
            // badge when a file is not present yet.
            $variant = (($i % 6) + 1);
            $image      = "/images/robots/{$type}/{$type}-{$variant}.png";
            $imageHover = "/images/robots/{$type}/{$type}-{$variant}.gif";

            $stmt->execute([
                $name, $type, $status, $battery, $model, $serial, $firmware,
                $lat, $lng, $created, $maxDuty, $dutyUsed, $image, $imageHover,
                $dutyClass, $reserve,
            ]);
            $robotId = $stmt->fetchColumn();

            // Assign 1-2 Departments
            $d = $deptIds[array_rand($deptIds)];
            $deptStmt->execute([$robotId, $d]);

            // Assign 1-3 Arenas from the robot's own district -- a warehouse
            // unit is not stationed in a surgical theatre, and charging docks
            // are dispatch targets rather than postings.
            $numArenas = rand(1, 3);
            $shuffledArenas = array_column($sitesByDomain[$type], 'id');
            shuffle($shuffledArenas);
            $numArenas = min($numArenas, count($shuffledArenas));
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

    private function arenaIdByName($name)
    {
        $stmt = $this->pdo->prepare("SELECT id FROM arenas WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            throw new RuntimeException("Seed error: arena '$name' not found");
        }

        return $id;
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
            // Looked up by name: a positional index silently points at a
            // different site as soon as the city layout changes.
            [['arena', $this->arenaIdByName('Chem Lab 1'), null]]
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
