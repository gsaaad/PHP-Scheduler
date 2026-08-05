<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The authenticated caller, resolved once per request and threaded through to
 * anything that needs to make an authorization decision.
 */
final class AuthContext
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly ?int $departmentId,
        public readonly array $roles,
        public readonly bool $isAdmin,
        public readonly bool $canSchedule,
        public readonly bool $canMaintain,
        public readonly string $via, // 'token' | 'session'
    ) {
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /** @return array<string, mixed> safe to return to the caller */
    public function toArray(): array
    {
        return [
            'user_id'       => $this->userId,
            'username'      => $this->username,
            'department_id' => $this->departmentId,
            'roles'         => $this->roles,
            'is_admin'      => $this->isAdmin,
            'can_schedule'  => $this->canSchedule,
            'can_maintain'  => $this->canMaintain,
            'authenticated_via' => $this->via,
        ];
    }
}
