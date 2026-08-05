<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * 403: the caller is authenticated but not permitted.
 *
 * Used both for missing role permissions and for robots outside the caller's
 * access rules. Deliberately worded so it does not reveal whether an
 * out-of-scope robot exists.
 */
class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Not permitted', ?Throwable $previous = null)
    {
        parent::__construct($message, 403, $previous);
    }

    public static function robotOutOfScope(int $robotId): self
    {
        return new self("Robot {$robotId} is not within your department's access rules.");
    }

    public static function missingPermission(string $permission): self
    {
        return new self("This action requires the '{$permission}' permission.");
    }
}
