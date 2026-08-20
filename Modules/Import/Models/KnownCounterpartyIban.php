<?php

declare(strict_types=1);

namespace Modules\Import\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

// Every read and write carries an explicit where('user_id', …):
// BelongsToUser's global scope does not fire on queue, console or factory
// paths. `real_iban` is unvalidated because its only writer is a seeder.
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
