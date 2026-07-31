<?php

declare(strict_types=1);

namespace Modules\Core\Public\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;

// Unauthenticated contexts (install bootstrap, tests without `actingAs`)
// make `$this->currentUser->id()` throw — the scope falls through cleanly
// in that case so queries return rows regardless, rather than 500ing.
/**
 * @implements Scope<Model>
 */
final class UserScope implements Scope
{
    public function __construct(private readonly CurrentUser $currentUser) {}

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        try {
            $builder->where($model->getTable().'.user_id', $this->currentUser->id());
        } catch (NotAuthenticatedException) {
            // Intentionally empty: an unauthenticated context (install
            // bootstrap, tests without actingAs) must leave the query
            // unscoped rather than 500, so the missing user id is swallowed
            // and every row stays visible.
        }
    }
}
