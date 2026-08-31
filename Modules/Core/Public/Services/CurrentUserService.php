<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;

final readonly class CurrentUserService implements CurrentUser
{
    public function __construct(
        private AuthFactory $auth,
    ) {}

    public function id(): int
    {
        return $this->resolveUser()->id;
    }

    public function user(): User
    {
        return $this->resolveUser();
    }

    public function periodStartDay(): int
    {
        $day = $this->resolveUser()->period_start_day;

        return max($day, 1);
    }

    public function isAuthenticated(): bool
    {
        return $this->auth->guard()->user() instanceof User;
    }

    private function resolveUser(): User
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            throw new NotAuthenticatedException('No authenticated user is bound to the current guard.');
        }

        return $user;
    }
}
