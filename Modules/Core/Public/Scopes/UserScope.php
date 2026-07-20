<?php

declare(strict_types=1);

namespace Modules\Core\Public\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;

/**
 * Global Eloquent scope that filters domain models by the current user's id.
 * Resolves the user through the injected `CurrentUser` contract; in
 * unauthenticated contexts (install bootstrap, tests without `actingAs`) the
 * scope falls through cleanly so queries return rows regardless.
 *
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
        }
    }
}
