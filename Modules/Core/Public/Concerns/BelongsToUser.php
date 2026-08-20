<?php

declare(strict_types=1);

namespace Modules\Core\Public\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;

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
