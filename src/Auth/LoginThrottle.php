<?php

declare(strict_types=1);

namespace App\Auth;

use App\Exceptions\TooManyRequestsException;
use PDO;

/**
 * Caps failed sign-in attempts per client address.
 *
 * Unthrottled, `POST /api/auth/login` is both a credential-stuffing surface and
 * a cheap denial-of-service one: bcrypt is deliberately expensive, so an
 * attacker can burn the CPU of the box by sending passwords that are certain to
 * be wrong.
 *
 * The counter reads `audit_logs` rather than a table of its own. Every failed
 * login already writes a row there with the action, the outcome and the client
 * address, so a dedicated store would be a second copy of a record the
 * application is already required to keep -- and one that could disagree with
 * the audit trail about what happened.
 *
 * Only failures count. A successful sign-in is not throttled, so an operator
 * working normally never meets this, however busy the shift.
 */
class LoginThrottle
{
    /** Failures tolerated from one address within the window. */
    public const MAX_FAILURES = 10;

    /** How far back the count reaches, and how long a lockout lasts. */
    public const WINDOW_MINUTES = 15;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @throws TooManyRequestsException when the address is over its allowance
     */
    public function assertWithinLimit(?string $ip): void
    {
        // With no address there is nothing to key on. Rather than throttle every
        // anonymous caller as one bucket -- which would let a single client lock
        // out everyone -- this lets the request through; the audit row is still
        // written either way.
        if ($ip === null || $ip === '') {
            return;
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM audit_logs
             WHERE action = 'auth.login'
               AND outcome = 'denied'
               AND ip_address = :ip
               AND created_at > CURRENT_TIMESTAMP - (:minutes || ' minutes')::interval"
        );
        $stmt->bindValue('ip', $ip, PDO::PARAM_STR);
        $stmt->bindValue('minutes', (string) self::WINDOW_MINUTES, PDO::PARAM_STR);
        $stmt->execute();

        if ((int) $stmt->fetchColumn() < self::MAX_FAILURES) {
            return;
        }

        throw new TooManyRequestsException(
            sprintf(
                'Too many failed sign-in attempts. Try again in %d minutes.',
                self::WINDOW_MINUTES
            ),
            self::WINDOW_MINUTES * 60
        );
    }
}
