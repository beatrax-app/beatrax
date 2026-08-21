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

    /** @var bool */
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
