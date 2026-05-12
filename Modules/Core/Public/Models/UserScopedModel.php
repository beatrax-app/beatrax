<?php

declare(strict_types=1);

namespace Modules\Core\Public\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Abstract base for every domain model that belongs to a user. Wires up the
 * `BelongsToUser` trait so concrete subclasses inherit `user_id` fillable,
 * the `user()` relationship, and the global `UserScope` automatically.
 *
 * Subclasses declare their own `$table`, `$fillable`, and `$casts` as usual.
 */
abstract class UserScopedModel extends Model
{
    use BelongsToUser;
}
