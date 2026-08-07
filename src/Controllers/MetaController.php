<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AccessPolicy;
use App\Auth\AuthContext;
use App\Http\JsonResponse;
use App\Models\Geography;
use Closure;
use PDO;

/**
 * Reference data for populating filters.
 *
 * Each list is derived from what the caller can actually reach, so the UI never
 * offers an arena or capability that would return an empty set -- and never
 * discloses the existence of labs outside the caller's scope.
 */
class MetaController
{
    private ?PDO $connection = null;

    public function __construct(
        private readonly Closure $db,
        private readonly AuthContext $auth,
    ) {
    }

    public function arenas(): void
    {
        $access = (new AccessPolicy($this->conn()))->robotFilter($this->auth);

        $sql = "SELECT a.id, a.name, a.type, COUNT(DISTINCT r.id) AS robot_count
                FROM arenas a
                JOIN robot_arenas ra ON ra.arena_id = a.id
                JOIN robots r        ON r.id = ra.robot_id
                WHERE {$access['sql']}
                GROUP BY a.id, a.name, a.type
                HAVING COUNT(DISTINCT r.id) > 0
                ORDER BY a.name";

        $stmt = $this->conn()->prepare($sql);
        foreach ($access['params'] as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        JsonResponse::send(['data' => $stmt->fetchAll()]);
    }

    public function capabilities(): void
    {
        $access = (new AccessPolicy($this->conn()))->robotFilter($this->auth);

        $sql = "SELECT c.id, c.name, COUNT(DISTINCT r.id) AS robot_count
                FROM capabilities c
                JOIN robot_capabilities rc ON rc.capability_id = c.id
                JOIN robots r              ON r.id = rc.robot_id
                WHERE {$access['sql']}
                GROUP BY c.id, c.name
                HAVING COUNT(DISTINCT r.id) > 0
                ORDER BY c.name";

        $stmt = $this->conn()->prepare($sql);
        foreach ($access['params'] as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        JsonResponse::send(['data' => $stmt->fetchAll()]);
    }

    /** Fleet counts for the caller's scope -- drives the dashboard summary. */
    public function summary(): void
    {
        $access = (new AccessPolicy($this->conn()))->robotFilter($this->auth);

        $sql = "SELECT r.status, COUNT(*) AS count
                FROM robots r WHERE {$access['sql']}
                GROUP BY r.status";

        $stmt = $this->conn()->prepare($sql);
        foreach ($access['params'] as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $byStatus = [];
        $total    = 0;
        foreach ($stmt->fetchAll() as $row) {
            $byStatus[$row['status']] = (int) $row['count'];
            $total                   += (int) $row['count'];
        }

        // Upcoming bookings, scoped the same way.
        $sql = "SELECT COUNT(*) FROM schedules s
                JOIN robots r ON r.id = s.robot_id
                WHERE {$access['sql']} AND s.status = 'scheduled' AND s.end_time > CURRENT_TIMESTAMP";
        $stmt = $this->conn()->prepare($sql);
        foreach ($access['params'] as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        JsonResponse::send([
            'data' => [
                'total_robots'      => $total,
                'by_status'         => $byStatus,
                'upcoming_bookings' => (int) $stmt->fetchColumn(),
            ],
        ]);
    }

    /** The RobotCity map: every site, plus robot positions within the caller's scope. */
    public function map(): void
    {
        $geo    = new Geography($this->conn());
        $access = (new AccessPolicy($this->conn()))->robotFilter($this->auth);

        JsonResponse::send([
            'data' => [
                'sites'  => $geo->sites(),
                'robots' => $geo->robotPositions($access),
            ],
        ]);
    }

    private function conn(): PDO
    {
        return $this->connection ??= ($this->db)();
    }
}
