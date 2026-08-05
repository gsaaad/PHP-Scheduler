<?php

declare(strict_types=1);

namespace App\Auth;

use App\Exceptions\UnauthorizedException;
use DateTimeImmutable;
use PDO;

/**
 * Resolves a request into an AuthContext via either a bearer API token or a
 * session cookie.
 *
 * Only hashes are stored. Tokens and session ids are shown to the caller once
 * and never persisted in plaintext, so a database disclosure yields nothing
 * replayable. Lookups are by SHA-256 of the presented value, which is a
 * constant-length indexed column.
 */
class Authenticator
{
    public const SESSION_COOKIE   = 'ace_session';
    public const TOKEN_PREFIX     = 'ace_';
    private const SESSION_TTL     = '+12 hours';
    private const TOKEN_BYTES     = 32;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    public function resolve(array $headers, array $cookies): ?AuthContext
    {
        $authorization = $headers['authorization'] ?? $headers['Authorization'] ?? null;

        if (is_string($authorization) && preg_match('/^Bearer\s+(\S+)$/i', $authorization, $m) === 1) {
            return $this->fromToken($m[1]);
        }

        $cookie = $cookies[self::SESSION_COOKIE] ?? null;
        if (is_string($cookie) && $cookie !== '') {
            return $this->fromSession($cookie);
        }

        return null;
    }

    // ------------------------------------------------------------ tokens

    /**
     * Issues a token. The plaintext is returned once here and is not
     * recoverable afterwards.
     *
     * @return array{token: string, id: int, expires_at: ?string}
     */
    public function issueToken(int $userId, string $name, ?DateTimeImmutable $expiresAt = null): array
    {
        $plain = self::TOKEN_PREFIX . bin2hex(random_bytes(self::TOKEN_BYTES));

        $stmt = $this->db->prepare(
            'INSERT INTO api_tokens (user_id, name, token_hash, expires_at)
             VALUES (?, ?, ?, ?) RETURNING id, expires_at'
        );
        $stmt->execute([$userId, $name, self::hash($plain), $expiresAt?->format('Y-m-d H:i:s')]);
        $row = $stmt->fetch();

        return [
            'token'      => $plain,
            'id'         => (int) $row['id'],
            'expires_at' => $row['expires_at'],
        ];
    }

    public function revokeToken(int $tokenId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE api_tokens SET revoked_at = CURRENT_TIMESTAMP
             WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$tokenId, $userId]);

        return $stmt->rowCount() > 0;
    }

    private function fromToken(string $plain): ?AuthContext
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id FROM api_tokens
             WHERE token_hash = ?
               AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)'
        );
        $stmt->execute([self::hash($plain)]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        // Best-effort; never let telemetry break the request
        $this->db->prepare('UPDATE api_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$row['id']]);

        return $this->contextFor((int) $row['user_id'], 'token');
    }

    // ---------------------------------------------------------- sessions

    /**
     * Verifies credentials and starts a session.
     *
     * @return array{session_id: string, expires_at: string, context: AuthContext}
     */
    public function login(string $username, string $password, ?string $ip, ?string $userAgent): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, password_hash FROM users WHERE username = ? OR email = ?'
        );
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        // Hash even when the user is missing, so a failed lookup and a wrong
        // password take comparable time.
        $hash = $user === false
            ? '$2y$12$usernamedoesnotexistusernamedoesnotexistusernamedoesnotexis'
            : $user['password_hash'];

        if (!password_verify($password, $hash) || $user === false) {
            throw new UnauthorizedException('Invalid username or password.');
        }

        $plain     = bin2hex(random_bytes(self::TOKEN_BYTES));
        $expiresAt = (new DateTimeImmutable())->modify(self::SESSION_TTL);

        $stmt = $this->db->prepare(
            'INSERT INTO sessions (id, user_id, ip_address, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            self::hash($plain),
            $user['id'],
            $ip,
            $userAgent === null ? null : substr($userAgent, 0, 255),
            $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return [
            'session_id' => $plain,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'context'    => $this->contextFor((int) $user['id'], 'session'),
        ];
    }

    public function logout(string $sessionId): void
    {
        $this->db->prepare('DELETE FROM sessions WHERE id = ?')->execute([self::hash($sessionId)]);
    }

    /** Housekeeping for expired sessions; safe to call from a cron or on login. */
    public function pruneExpiredSessions(): int
    {
        $stmt = $this->db->query('DELETE FROM sessions WHERE expires_at <= CURRENT_TIMESTAMP');

        return $stmt->rowCount();
    }

    private function fromSession(string $plain): ?AuthContext
    {
        $stmt = $this->db->prepare(
            'SELECT user_id FROM sessions WHERE id = ? AND expires_at > CURRENT_TIMESTAMP'
        );
        $stmt->execute([self::hash($plain)]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->contextFor((int) $row['user_id'], 'session');
    }

    // ----------------------------------------------------------- shared

    private function contextFor(int $userId, string $via): ?AuthContext
    {
        $stmt = $this->db->prepare(
            'SELECT u.id, u.username, u.department_id,
                    COALESCE(
                        ARRAY_AGG(r.name ORDER BY r.name) FILTER (WHERE r.name IS NOT NULL),
                        ARRAY[]::varchar[]
                    ) AS role_names,
                    BOOL_OR(COALESCE(r.is_admin, FALSE))     AS is_admin,
                    BOOL_OR(COALESCE(r.can_schedule, FALSE)) AS can_schedule,
                    BOOL_OR(COALESCE(r.can_maintain, FALSE)) AS can_maintain
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r       ON r.id = ur.role_id
             WHERE u.id = ?
             GROUP BY u.id, u.username, u.department_id'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return new AuthContext(
            userId:       (int) $row['id'],
            username:     (string) $row['username'],
            departmentId: $row['department_id'] === null ? null : (int) $row['department_id'],
            roles:        self::parsePgArray((string) $row['role_names']),
            isAdmin:      self::pgBool($row['is_admin']),
            canSchedule:  self::pgBool($row['can_schedule']),
            canMaintain:  self::pgBool($row['can_maintain']),
            via:          $via,
        );
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    private static function pgBool(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
    }

    /** Postgres returns ARRAY_AGG as the literal `{a,b}`; PDO does not decode it. */
    private static function parsePgArray(string $literal): array
    {
        $trimmed = trim($literal, '{}');
        if ($trimmed === '') {
            return [];
        }

        return array_map(
            static fn (string $v) => trim($v, '"'),
            str_getcsv($trimmed, ',', '"', '\\')
        );
    }
}
