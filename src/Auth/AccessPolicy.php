<?php

declare(strict_types=1);

namespace App\Auth;

use App\Exceptions\ForbiddenException;
use PDO;

/**
 * Attribute-based access to the fleet.
 *
 * A department reaches a robot when ANY of its access rules matches, and a rule
 * matches when ALL of its criteria hold. Expressed in SQL as "there exists a
 * rule for which no criterion fails", which makes a rule with zero criteria an
 * unrestricted grant.
 *
 * Worked examples:
 *   Marine lab: one rule with criteria [capability: Terrain Walking,
 *               capability: Submersible] -> only robots that walk AND swim.
 *   Amphibious: two rules, one per capability -> robots that swim OR float.
 *   Biology lab: one rule with criterion [department: Biology].
 *   Chem Lab operators: one rule with criterion [arena: Chem Lab 1].
 *
 * The filter is composed into queries rather than applied afterwards, so
 * out-of-scope robots are never fetched, counted, or paginated over.
 */
class AccessPolicy
{
    public const PARAM = 'acl_department_id';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * A boolean SQL fragment constraining `$alias` to what the caller may see.
     *
     * @return array{sql: string, params: array<string, mixed>}
     */
    public function robotFilter(AuthContext $auth, string $alias = 'r'): array
    {
        // Fleet administrators bypass rules entirely.
        if ($auth->isAdmin) {
            return ['sql' => 'TRUE', 'params' => []];
        }

        // A user with no department has no rules, and therefore no fleet.
        if ($auth->departmentId === null) {
            return ['sql' => 'FALSE', 'params' => []];
        }

        $p   = self::PARAM;
        $sql = "EXISTS (
                    SELECT 1
                    FROM access_rules ar
                    WHERE ar.department_id = :{$p}
                      AND NOT EXISTS (
                          SELECT 1
                          FROM access_rule_criteria c
                          WHERE c.rule_id = ar.id
                            AND NOT (
                                   (c.kind = 'arena' AND EXISTS (
                                        SELECT 1 FROM robot_arenas ra
                                        WHERE ra.robot_id = {$alias}.id AND ra.arena_id = c.ref_id))
                                OR (c.kind = 'capability' AND EXISTS (
                                        SELECT 1 FROM robot_capabilities rc
                                        WHERE rc.robot_id = {$alias}.id AND rc.capability_id = c.ref_id))
                                OR (c.kind = 'department' AND EXISTS (
                                        SELECT 1 FROM robot_departments rd
                                        WHERE rd.robot_id = {$alias}.id AND rd.department_id = c.ref_id))
                                OR (c.kind = 'type' AND {$alias}.type = c.ref_value)
                            )
                      )
                )";

        return ['sql' => $sql, 'params' => [$p => $auth->departmentId]];
    }

    public function canAccessRobot(AuthContext $auth, int $robotId): bool
    {
        $filter = $this->robotFilter($auth, 'r');

        $stmt = $this->db->prepare(
            "SELECT 1 FROM robots r WHERE r.id = :robot_id AND {$filter['sql']} LIMIT 1"
        );
        $stmt->execute(['robot_id' => $robotId] + $filter['params']);

        return $stmt->fetch() !== false;
    }

    /**
     * Deliberately does not distinguish "no such robot" from "out of scope" --
     * that difference would let a caller enumerate the fleet beyond their reach.
     */
    public function assertCanAccessRobot(AuthContext $auth, int $robotId): void
    {
        if (!$this->canAccessRobot($auth, $robotId)) {
            throw ForbiddenException::robotOutOfScope($robotId);
        }
    }

    /**
     * How many robots the caller can reach. Useful for the dashboard and for
     * explaining an empty list.
     */
    public function accessibleRobotCount(AuthContext $auth): int
    {
        $filter = $this->robotFilter($auth, 'r');

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM robots r WHERE {$filter['sql']}");
        $stmt->execute($filter['params']);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The rules backing the caller's access, so an operator can see *why* a
     * robot is or is not in scope rather than guessing.
     *
     * @return list<array<string, mixed>>
     */
    public function describeRules(AuthContext $auth): array
    {
        if ($auth->isAdmin) {
            return [[
                'rule'        => 'Fleet administrator',
                'description' => 'Unrestricted access to every robot.',
                'criteria'    => [],
            ]];
        }

        if ($auth->departmentId === null) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT ar.id, ar.name, ar.description,
                    c.kind,
                    COALESCE(a.name, cap.name, d.name, c.ref_value) AS criterion
             FROM access_rules ar
             LEFT JOIN access_rule_criteria c ON c.rule_id = ar.id
             LEFT JOIN arenas a       ON c.kind = 'arena'      AND a.id   = c.ref_id
             LEFT JOIN capabilities cap ON c.kind = 'capability' AND cap.id = c.ref_id
             LEFT JOIN departments d  ON c.kind = 'department' AND d.id   = c.ref_id
             WHERE ar.department_id = ?
             ORDER BY ar.id, c.id"
        );
        $stmt->execute([$auth->departmentId]);

        $rules = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['id'];
            $rules[$id] ??= [
                'rule'        => $row['name'],
                'description' => $row['description'],
                'match'       => 'all of',
                'criteria'    => [],
            ];
            if ($row['kind'] !== null) {
                $rules[$id]['criteria'][] = ['kind' => $row['kind'], 'value' => $row['criterion']];
            }
        }

        return array_values($rules);
    }
}
