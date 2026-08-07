<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Auth\LoginThrottle;
use App\Exceptions\TooManyRequestsException;

/**
 * The throttle counts failed sign-ins straight out of audit_logs rather than
 * keeping a tally of its own, so these tests write the same rows the auth
 * controller writes and assert the count is read back correctly.
 */
class LoginThrottleTest extends DatabaseTestCase
{
    /** Unique per run so concurrent runs and leftovers cannot cross-talk. */
    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();
        // A documentation-range address (RFC 5737), never a real client.
        $this->ip = '203.0.113.' . random_int(2, 254);
        $this->purge();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->purge();
        }
        parent::tearDown();
    }

    private function purge(): void
    {
        $this->db->prepare('DELETE FROM audit_logs WHERE ip_address = ?')->execute([$this->ip]);
    }

    private function recordFailures(int $count, string $ageInterval = '1 minute'): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO audit_logs (user_id, action, entity_type, details, outcome, ip_address, created_at)
             VALUES (NULL, 'auth.login', 'user', '{}', 'denied', ?, CURRENT_TIMESTAMP - ?::interval)"
        );
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([$this->ip, $ageInterval]);
        }
    }

    private function throttle(): LoginThrottle
    {
        return new LoginThrottle($this->db);
    }

    public function testAllowsAttemptsBelowTheLimit(): void
    {
        $this->recordFailures(LoginThrottle::MAX_FAILURES - 1);

        $this->throttle()->assertWithinLimit($this->ip);
        $this->addToAssertionCount(1); // no exception is the assertion
    }

    public function testBlocksOnceTheLimitIsReached(): void
    {
        $this->recordFailures(LoginThrottle::MAX_FAILURES);

        $this->expectException(TooManyRequestsException::class);
        $this->throttle()->assertWithinLimit($this->ip);
    }

    public function testTheRefusalCarriesARetryWindow(): void
    {
        $this->recordFailures(LoginThrottle::MAX_FAILURES);

        try {
            $this->throttle()->assertWithinLimit($this->ip);
            $this->fail('Expected TooManyRequestsException');
        } catch (TooManyRequestsException $e) {
            $this->assertSame(429, $e->getStatusCode());
            // Told when to come back, rather than left to guess.
            $this->assertSame(LoginThrottle::WINDOW_MINUTES * 60, $e->getRetryAfterSeconds());
            $this->assertSame(['retry_after' => LoginThrottle::WINDOW_MINUTES * 60], $e->getContext());
        }
    }

    /** A lockout has to expire on its own, or one bad afternoon bans an office. */
    public function testFailuresOlderThanTheWindowNoLongerCount(): void
    {
        $this->recordFailures(
            LoginThrottle::MAX_FAILURES * 2,
            (LoginThrottle::WINDOW_MINUTES + 5) . ' minutes'
        );

        $this->throttle()->assertWithinLimit($this->ip);
        $this->addToAssertionCount(1);
    }

    /** Throttling is per address: one attacker must not lock out everyone. */
    public function testOtherAddressesAreUnaffected(): void
    {
        $this->recordFailures(LoginThrottle::MAX_FAILURES * 2);

        $this->throttle()->assertWithinLimit('198.51.100.7');
        $this->addToAssertionCount(1);
    }

    /** Successful sign-ins are not failures, however many there are. */
    public function testSuccessfulLoginsDoNotCountTowardTheLimit(): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO audit_logs (user_id, action, entity_type, details, outcome, ip_address)
             VALUES (NULL, 'auth.login', 'user', '{}', 'success', ?)"
        );
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES * 2; $i++) {
            $stmt->execute([$this->ip]);
        }

        $this->throttle()->assertWithinLimit($this->ip);
        $this->addToAssertionCount(1);
    }

    /**
     * With no address there is no bucket to key on. Throttling every unknown
     * caller as one would let a single client lock out the rest.
     */
    public function testAnUnknownAddressIsNotThrottled(): void
    {
        $this->throttle()->assertWithinLimit(null);
        $this->throttle()->assertWithinLimit('');
        $this->addToAssertionCount(1);
    }
}
