<?php

declare(strict_types=1);

namespace App\Audit;

use App\Auth\AuthContext;
use PDO;
use Throwable;

/**
 * Writes an audit trail for every mutation and every denied attempt.
 *
 * Called from the controller layer, deliberately outside the model's business
 * transaction: a booking that rolls back must still leave a record that it was
 * attempted and refused. Writing inside the transaction would erase exactly the
 * events an auditor cares about.
 *
 * An audit failure never breaks the request -- it degrades to the error log.
 */
class AuditLogger
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string, mixed> $details */
    public function record(
        ?AuthContext $auth,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = [],
        string $outcome = 'success',
        ?string $ip = null,
    ): void {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, outcome, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $auth?->userId,
                $action,
                $entityType,
                $entityId,
                $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES),
                $outcome,
                $ip,
            ]);
        } catch (Throwable $e) {
            error_log('[audit] failed to record ' . $action . ': ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $details */
    public function denied(
        ?AuthContext $auth,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = [],
        ?string $ip = null,
    ): void {
        $this->record($auth, $action, $entityType, $entityId, $details, 'denied', $ip);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 50, int $offset = 0, ?int $userId = null): array
    {
        $sql = 'SELECT al.*, u.username
                FROM audit_logs al
                LEFT JOIN users u ON u.id = al.user_id';
        $params = [];

        if ($userId !== null) {
            $sql .= ' WHERE al.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $sql .= ' ORDER BY al.created_at DESC, al.id DESC LIMIT :limit OFFSET :offset';
        $params['limit']  = $limit;
        $params['offset'] = $offset;

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
