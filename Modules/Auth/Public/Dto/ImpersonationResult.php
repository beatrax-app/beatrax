<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Dto;

use Modules\Core\Models\User;

/**
 * Result of a profile-switch attempt.
 *
 * `status` is one of `success`, `wrong_password`, `not_allowed`, or
 * `invalid_target`. On a successful switch `actingAs` carries the user
 * the guard now resolves to; on every failure it is null.
 *
 * The class is pure data with a private constructor and named static
 * factories — callers never instantiate it directly, so an invalid
 * status string can never be constructed.
 */
final class ImpersonationResult
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_WRONG_PASSWORD = 'wrong_password';

    public const STATUS_NOT_ALLOWED = 'not_allowed';

    public const STATUS_INVALID_TARGET = 'invalid_target';

    private function __construct(
        public readonly string $status,
        public readonly ?User $actingAs,
    ) {}

    public static function success(User $actingAs): self
    {
        return new self(self::STATUS_SUCCESS, $actingAs);
    }

    public static function wrongPassword(): self
    {
        return new self(self::STATUS_WRONG_PASSWORD, null);
    }

    public static function notAllowed(): self
    {
        return new self(self::STATUS_NOT_ALLOWED, null);
    }

    public static function invalidTarget(): self
    {
        return new self(self::STATUS_INVALID_TARGET, null);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }
}
