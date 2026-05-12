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
 * `Container::getInstance()` is intentionally used here — it is a static
 * accessor on the container class itself, NOT a Laravel facade, and is the
 * documented contract-free way to resolve services from Eloquent boot hooks
 * (which cannot accept constructor parameters).
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
