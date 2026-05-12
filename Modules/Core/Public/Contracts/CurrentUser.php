<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;

/**
 * Public contract that domain code injects to read the authenticated user.
 *
 * Implementations resolve the user through Laravel's auth guard contract so
 * the rest of the codebase never depends on `auth()`, `Auth::user()`, or the
 * `Guard` interface directly. The seam exists so the v2 transition to a
 * second user only changes binding/registration, not call sites.
 */
interface CurrentUser
{
    /**
     * Returns the authenticated user's primary key.
     *
     * @throws NotAuthenticatedException when no user is bound to the guard.
     */
    public function id(): int;

    /**
     * Returns the authenticated user.
     *
     * @throws NotAuthenticatedException when no user is bound to the guard.
     */
    public function user(): User;

    /**
     * Returns the user's configured period start day (1..28). Defaults to 1.
     *
     * @throws NotAuthenticatedException when no user is bound to the guard.
     */
    public function periodStartDay(): int;

    /**
     * Returns true when a user is bound to the active guard.
     */
    public function isAuthenticated(): bool;
}
