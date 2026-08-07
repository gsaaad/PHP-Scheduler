<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * RobotCity: where sites are, and which one a robot is standing in.
 *
 * A robot carries coordinates, not an arena id — it is somewhere in the city,
 * and the site it is "at" is derived by proximity. Outside every site's radius
 * it is in transit, which is a real state rather than missing data.
 */
class Geography
{
    /** Robot disciplines that each get their own district of five sites. */
    public const DOMAINS = ['security', 'healthcare', 'research', 'military', 'warehouse'];
    public const CHARGING = 'charging';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Great-circle distance in metres, as a SQL expression.
     *
     * Haversine rather than a planar approximation: the city spans only a few
     * kilometres so either would do, but this stays correct if the deployment
     * ever covers a wider area.
     */
    public static function distanceSql(string $robotAlias = 'r', string $arenaAlias = 'a'): string
    {
        return "6371000 * 2 * ASIN(LEAST(1, SQRT(
            POWER(SIN(RADIANS({$arenaAlias}.latitude - {$robotAlias}.current_location_lat) / 2), 2)
            + COS(RADIANS({$robotAlias}.current_location_lat))
            * COS(RADIANS({$arenaAlias}.latitude))
            * POWER(SIN(RADIANS({$arenaAlias}.longitude - {$robotAlias}.current_location_lng) / 2), 2)
        )))";
    }

    /**
     * The site a robot is currently within, or null when it is between sites.
     *
     * @return array{id: int, name: string, domain: string, code: ?string, distance_m: float}|null
     */
    public function locate(int $robotId): ?array
    {
        $distance = self::distanceSql();

        $stmt = $this->db->prepare(
            "SELECT a.id, a.name, a.domain, a.code, {$distance} AS distance_m
             FROM robots r
             CROSS JOIN arenas a
             WHERE r.id = ?
               AND r.current_location_lat IS NOT NULL
               AND a.latitude IS NOT NULL
               AND {$distance} <= a.radius_m
             ORDER BY distance_m ASC
             LIMIT 1"
        );
        $stmt->execute([$robotId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id'         => (int) $row['id'],
            'name'       => (string) $row['name'],
            'domain'     => (string) $row['domain'],
            'code'       => $row['code'],
            'distance_m' => round((float) $row['distance_m'], 1),
        ];
    }

    /**
     * Nearest site regardless of radius — used to describe a robot in transit
     * ("420 m from Mercy Wing 2") rather than reporting an unhelpful null.
     *
     * @return array{id: int, name: string, domain: string, distance_m: float}|null
     */
    public function nearest(int $robotId, ?string $domain = null): ?array
    {
        $distance = self::distanceSql();
        $filter   = $domain === null ? '' : ' AND a.domain = :domain';

        $stmt = $this->db->prepare(
            "SELECT a.id, a.name, a.domain, {$distance} AS distance_m
             FROM robots r
             CROSS JOIN arenas a
             WHERE r.id = :robot_id
               AND r.current_location_lat IS NOT NULL
               AND a.latitude IS NOT NULL
               {$filter}
             ORDER BY distance_m ASC
             LIMIT 1"
        );
        $params = ['robot_id' => $robotId];
        if ($domain !== null) {
            $params['domain'] = $domain;
        }
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'id'         => (int) $row['id'],
            'name'       => (string) $row['name'],
            'domain'     => (string) $row['domain'],
            'distance_m' => round((float) $row['distance_m'], 1),
        ];
    }

    /** The closest charging station to a robot, for dispatch when duty runs out. */
    public function nearestChargingStation(int $robotId): ?array
    {
        return $this->nearest($robotId, self::CHARGING);
    }

    /**
     * Every site, for drawing the map.
     *
     * @return list<array<string, mixed>>
     */
    public function sites(): array
    {
        return $this->db->query(
            "SELECT id, name, code, domain, type, latitude, longitude, radius_m, capacity
             FROM arenas
             WHERE latitude IS NOT NULL
             ORDER BY domain, name"
        )->fetchAll();
    }

    /**
     * Robot positions for the map, constrained to the caller's access scope.
     *
     * @param array{sql: string, params: array<string, mixed>} $access
     * @return list<array<string, mixed>>
     */
    public function robotPositions(array $access, int $limit = 500): array
    {
        $sql = "SELECT r.id, r.name, r.type, r.status, r.battery_level,
                       r.current_location_lat AS lat, r.current_location_lng AS lng,
                       r.max_duty_minutes, r.duty_minutes_used
                FROM robots r
                WHERE {$access['sql']} AND r.current_location_lat IS NOT NULL
                ORDER BY r.id
                LIMIT :limit";

        $stmt = $this->db->prepare($sql);
        foreach ($access['params'] + ['limit' => $limit] as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
