<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;

interface CurrentUser
{
    /**
     * @throws NotAuthenticatedException when no user is bound to the guard.
     */
    public function id(): int;

    /**
     * @throws NotAuthenticatedException when no user is bound to the guard.
     */
    public function user(): User;

    /**
     * @throws NotAuthenticatedException when no user is bound to the guard.
     */
    public function periodStartDay(): int;

    public function isAuthenticated(): bool;
}
