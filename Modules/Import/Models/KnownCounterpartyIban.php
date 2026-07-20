<?php

declare(strict_types=1);

namespace Modules\Import\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

// Every read/write path carries an EXPLICIT where('user_id', $userId)
// filter — BelongsToUser's global scope is only a secondary guard
// (doesn't fire under queue/console/factory paths). `real_iban` has no
// shape validation yet (sole writer is a compile-time-constant seeder).
/**
 * @property int $id
 * @property int $user_id
 * @property string $real_iban
 * @property string $target_account_kind
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class KnownCounterpartyIban extends Model
{
    use BelongsToUser;

    /** @var string|null */
    protected $table = 'known_counterparty_ibans';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'real_iban',
        'target_account_kind',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
