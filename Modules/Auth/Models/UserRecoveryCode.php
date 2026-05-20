<?php

declare(strict_types=1);

namespace Modules\Auth\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Auth\Database\Factories\UserRecoveryCodeFactory;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Eloquent model for the user_recovery_codes table — one row models one
 * single-use account-recovery code issued to a user at signup.
 *
 * Each `code_hash` stores a one-way hash of the printed code. Consuming
 * a code stamps `used_at` rather than deleting the row, so the issued /
 * consumed set survives as an audit chain. The table carries
 * `created_at` (set by a SQLite default) but no `updated_at`; Eloquent's
 * auto-managed timestamps are disabled so the single post-insert write
 * is the `used_at` stamp.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $code_hash
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable $created_at
 */
final class UserRecoveryCode extends Model
{
    use BelongsToUser;

    /** @use HasFactory<UserRecoveryCodeFactory> */
    use HasFactory;

    /** @var string|null */
    protected $table = 'user_recovery_codes';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'code_hash',
        'used_at',
    ];

    /**
     * The table writes its own `created_at` via a SQLite `useCurrent()`
     * default and carries no `updated_at` column, so Eloquent's
     * auto-managed timestamps are disabled.
     *
     * @var bool
     */
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return UserRecoveryCodeFactory::new();
    }
}
