<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Auth\Authenticator;
use App\Exceptions\UnauthorizedException;
use DateTimeImmutable;

class AuthenticatorTest extends DatabaseTestCase
{
    private function authn(): Authenticator
    {
        return new Authenticator($this->db);
    }

    public function testLoginSucceedsAndYieldsAContext(): void
    {
        $dept = $this->insertDepartment();
        $role = $this->insertRole('Operator', canSchedule: true);
        $user = $this->insertUser($dept, $role, 'correct-horse');

        $result = $this->authn()->login($this->usernameOf($user), 'correct-horse', '127.0.0.1', 'phpunit');

        $this->assertSame($user, $result['context']->userId);
        $this->assertSame($dept, $result['context']->departmentId);
        $this->assertTrue($result['context']->canSchedule);
        $this->assertFalse($result['context']->isAdmin);
        $this->assertSame('session', $result['context']->via);
    }

    public function testLoginRejectsWrongPassword(): void
    {
        $user = $this->insertUser($this->insertDepartment(), null, 'correct-horse');

        $this->expectException(UnauthorizedException::class);
        $this->authn()->login($this->usernameOf($user), 'wrong', null, null);
    }

    public function testLoginRejectsUnknownUserWithoutRevealingIt(): void
    {
        try {
            $this->authn()->login('nobody-' . uniqid(), 'whatever', null, null);
            $this->fail('Expected UnauthorizedException');
        } catch (UnauthorizedException $e) {
            // Same wording as a wrong password: no user enumeration.
            $this->assertSame('Invalid username or password.', $e->getMessage());
        }
    }

    public function testRolesAggregateAcrossMultipleAssignments(): void
    {
        $dept  = $this->insertDepartment();
        $sched = $this->insertRole('Scheduler', canSchedule: true);
        $maint = $this->insertRole('Tech', canMaintain: true);
        $user  = $this->insertUser($dept, $sched, 'pw');
        $this->db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$user, $maint]);

        $ctx = $this->authn()->login($this->usernameOf($user), 'pw', null, null)['context'];

        $this->assertTrue($ctx->canSchedule);
        $this->assertTrue($ctx->canMaintain);
        $this->assertCount(2, $ctx->roles);
    }

    public function testUserWithNoRolesGetsNoPermissions(): void
    {
        $user = $this->insertUser($this->insertDepartment(), null, 'pw');
        $ctx  = $this->authn()->login($this->usernameOf($user), 'pw', null, null)['context'];

        $this->assertSame([], $ctx->roles);
        $this->assertFalse($ctx->isAdmin);
        $this->assertFalse($ctx->canSchedule);
        $this->assertFalse($ctx->canMaintain);
    }

    public function testSessionCookieResolvesBackToTheUser(): void
    {
        $user   = $this->insertUser($this->insertDepartment(), null, 'pw');
        $result = $this->authn()->login($this->usernameOf($user), 'pw', null, null);

        $ctx = $this->authn()->resolve([], [Authenticator::SESSION_COOKIE => $result['session_id']]);

        $this->assertNotNull($ctx);
        $this->assertSame($user, $ctx->userId);
    }

    public function testLogoutInvalidatesTheSession(): void
    {
        $user   = $this->insertUser($this->insertDepartment(), null, 'pw');
        $result = $this->authn()->login($this->usernameOf($user), 'pw', null, null);

        $this->authn()->logout($result['session_id']);

        $this->assertNull(
            $this->authn()->resolve([], [Authenticator::SESSION_COOKIE => $result['session_id']])
        );
    }

    /** Session ids are stored hashed; the plaintext must not be in the table. */
    public function testSessionIdIsNotStoredInPlaintext(): void
    {
        $user   = $this->insertUser($this->insertDepartment(), null, 'pw');
        $result = $this->authn()->login($this->usernameOf($user), 'pw', null, null);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM sessions WHERE id = ?');
        $stmt->execute([$result['session_id']]);

        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testBearerTokenResolvesBackToTheUser(): void
    {
        $user  = $this->insertUser($this->insertDepartment(), null, 'pw');
        $token = $this->authn()->issueToken($user, 'ci');

        $ctx = $this->authn()->resolve(['authorization' => 'Bearer ' . $token['token']], []);

        $this->assertNotNull($ctx);
        $this->assertSame($user, $ctx->userId);
        $this->assertSame('token', $ctx->via);
    }

    public function testTokenIsNotStoredInPlaintext(): void
    {
        $user  = $this->insertUser($this->insertDepartment(), null, 'pw');
        $token = $this->authn()->issueToken($user, 'ci');

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM api_tokens WHERE token_hash = ?');
        $stmt->execute([$token['token']]);

        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testRevokedTokenStopsWorking(): void
    {
        $user  = $this->insertUser($this->insertDepartment(), null, 'pw');
        $token = $this->authn()->issueToken($user, 'ci');

        $this->assertTrue($this->authn()->revokeToken($token['id'], $user));
        $this->assertNull($this->authn()->resolve(['authorization' => 'Bearer ' . $token['token']], []));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $user  = $this->insertUser($this->insertDepartment(), null, 'pw');
        $token = $this->authn()->issueToken($user, 'ci', new DateTimeImmutable('-1 hour'));

        $this->assertNull($this->authn()->resolve(['authorization' => 'Bearer ' . $token['token']], []));
    }

    public function testGarbageCredentialsResolveToNull(): void
    {
        $this->assertNull($this->authn()->resolve(['authorization' => 'Bearer not-a-real-token'], []));
        $this->assertNull($this->authn()->resolve(['authorization' => 'Basic abc'], []));
        $this->assertNull($this->authn()->resolve([], [Authenticator::SESSION_COOKIE => 'nope']));
        $this->assertNull($this->authn()->resolve([], []));
    }

    public function testOneUserCannotRevokeAnothersToken(): void
    {
        $owner    = $this->insertUser($this->insertDepartment(), null, 'pw');
        $attacker = $this->insertUser($this->insertDepartment(), null, 'pw');
        $token    = $this->authn()->issueToken($owner, 'ci');

        $this->assertFalse($this->authn()->revokeToken($token['id'], $attacker));
        $this->assertNotNull($this->authn()->resolve(['authorization' => 'Bearer ' . $token['token']], []));
    }

    private function usernameOf(int $userId): string
    {
        $stmt = $this->db->prepare('SELECT username FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return (string) $stmt->fetchColumn();
    }
}
