<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Audit\AuditLogger;
use App\Auth\AccessPolicy;
use App\Auth\AuthContext;
use App\Auth\Authenticator;
use App\Auth\LoginThrottle;
use App\Exceptions\UnauthorizedException;
use App\Exceptions\ValidationException;
use App\Http\JsonResponse;
use App\Http\Request;
use Closure;
use DateTimeImmutable;

class AuthController
{
    public function __construct(
        private readonly Closure $db,
        private readonly ?AuthContext $auth,
    ) {
    }

    public function login(): void
    {
        $body = Request::jsonBody();
        if (!is_array($body)) {
            throw new ValidationException(['body' => 'Request body must be a JSON object.']);
        }

        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        $errors = [];
        if ($username === '') {
            $errors['username'] = 'Username is required.';
        }
        if ($password === '') {
            $errors['password'] = 'Password is required.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $db    = ($this->db)();
        $audit = new AuditLogger($db);
        $authn = new Authenticator($db);

        // Before the password is checked, not after: verifying a bcrypt hash is
        // the expensive part, and doing it for a caller who is already over the
        // limit is the denial-of-service this guards against.
        (new LoginThrottle($db))->assertWithinLimit(Request::clientIp());

        try {
            $result = $authn->login($username, $password, Request::clientIp(), Request::userAgent());
        } catch (UnauthorizedException $e) {
            // Records the attempt without storing the submitted password.
            $audit->denied(null, 'auth.login', 'user', null, ['username' => $username], Request::clientIp());
            throw $e;
        }

        $authn->pruneExpiredSessions();

        // HttpOnly so scripts cannot read it; SameSite=Lax to blunt CSRF;
        // Secure whenever the request arrived over TLS.
        setcookie(Authenticator::SESSION_COOKIE, $result['session_id'], [
            'expires'  => strtotime($result['expires_at']),
            'path'     => '/',
            'httponly' => true,
            'secure'   => Request::isSecure(),
            'samesite' => 'Lax',
        ]);

        $audit->record($result['context'], 'auth.login', 'user', $result['context']->userId, [], 'success', Request::clientIp());

        JsonResponse::send([
            'message'    => 'Logged in',
            'expires_at' => $result['expires_at'],
            'user'       => $result['context']->toArray(),
        ]);
    }

    public function logout(): void
    {
        $cookie = $_COOKIE[Authenticator::SESSION_COOKIE] ?? null;
        $db     = ($this->db)();

        if (is_string($cookie) && $cookie !== '') {
            (new Authenticator($db))->logout($cookie);
        }

        setcookie(Authenticator::SESSION_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'secure'   => Request::isSecure(),
            'samesite' => 'Lax',
        ]);

        (new AuditLogger($db))->record($this->auth, 'auth.logout', 'user', $this->auth?->userId, [], 'success', Request::clientIp());

        JsonResponse::send(['message' => 'Logged out']);
    }

    /** Who am I, and what can I reach? */
    public function me(): void
    {
        $db     = ($this->db)();
        $policy = new AccessPolicy($db);

        JsonResponse::send([
            'user'   => $this->auth->toArray(),
            'access' => [
                'accessible_robots' => $policy->accessibleRobotCount($this->auth),
                'rules'             => $policy->describeRules($this->auth),
            ],
        ]);
    }

    /** Issues a bearer token. The plaintext appears in this response only. */
    public function createToken(): void
    {
        $body = Request::jsonBody();
        if (!is_array($body)) {
            throw new ValidationException(['body' => 'Request body must be a JSON object.']);
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(['name' => 'A token name is required.']);
        }

        $expiresAt = null;
        if (!empty($body['expires_at'])) {
            try {
                $expiresAt = new DateTimeImmutable((string) $body['expires_at']);
            } catch (\Exception) {
                throw new ValidationException(['expires_at' => 'Must be a valid datetime.']);
            }
        }

        $db     = ($this->db)();
        $result = (new Authenticator($db))->issueToken($this->auth->userId, $name, $expiresAt);

        (new AuditLogger($db))->record(
            $this->auth,
            'auth.token.create',
            'api_token',
            $result['id'],
            ['name' => $name],
            'success',
            Request::clientIp()
        );

        JsonResponse::send([
            'message'    => 'Token created. Store it now -- it cannot be retrieved again.',
            'id'         => $result['id'],
            'token'      => $result['token'],
            'expires_at' => $result['expires_at'],
        ], 201);
    }

    public function revokeToken(string $id): void
    {
        $db      = ($this->db)();
        $revoked = (new Authenticator($db))->revokeToken((int) $id, $this->auth->userId);

        (new AuditLogger($db))->record(
            $this->auth,
            'auth.token.revoke',
            'api_token',
            (int) $id,
            [],
            $revoked ? 'success' : 'not_found',
            Request::clientIp()
        );

        JsonResponse::send(['message' => $revoked ? 'Token revoked' : 'Token not found or already revoked']);
    }
}
