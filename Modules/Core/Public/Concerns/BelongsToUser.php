<?php

declare(strict_types=1);

namespace Modules\Core\Public\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;

/**
 * Domain models with a nullable `user_id` column use this trait to gain:
 *
 * 1. `user_id` added to `$fillable`
 * 2. A global scope filtering by the current user (when auth is bound)
 * 3. A `user()` belongs-to relationship
 *
 * `Container::getInstance()->make()` is used inside `bootBelongsToUser`
 * because Eloquent boot hooks run as static methods and cannot accept
 * constructor arguments — there is no path to inject the `UserScope`
 * directly. The static accessor is on the Container class itself rather
 * than a Laravel facade, but readers should treat it as the single
 * acceptable container touch-point in the codebase.
 */
trait BelongsToUser
{
    public static function bootBelongsToUser(): void
    {
        static::addGlobalScope(Container::getInstance()->make(UserScope::class));
    }

    public function initializeBelongsToUser(): void
    {
        $this->fillable = array_values(array_unique([...$this->fillable, 'user_id']));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
