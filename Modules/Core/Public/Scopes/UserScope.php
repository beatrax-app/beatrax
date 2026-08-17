<?php

declare(strict_types=1);

namespace Modules\Core\Public\Scopes;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;

/**
 * @implements Scope<Model>
 */
final class UserScope implements Scope
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly Application $app,
    ) {}

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        try {
            $builder->where($model->getTable().'.user_id', $this->currentUser->id());
        } catch (NotAuthenticatedException) {
            // A web request with no authenticated user must not read a per-user
            // table unscoped, so it matches nothing rather than leaking every
            // user's rows. The console — install bootstrap, queue workers, the
            // test suite — is not an attacker surface and stays unscoped.
            if (! $this->app->runningInConsole()) {
                $builder->getQuery()->whereRaw('1 = 0');
            }
        }
    }
}
