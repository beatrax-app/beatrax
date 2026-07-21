<?php

declare(strict_types=1);

namespace Modules\Chains\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Ledger\Models\Transaction;

/**
 * @link ../../../.docs/architecture/chain-resolution.md
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $from_transaction_id
 * @property int|null $to_transaction_id
 * @property string $kind
 * @property string $state
 * @property string $confidence
 * @property string $resolver
 * @property array<string, mixed> $evidence
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ChainLink extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'from_transaction_id',
        'to_transaction_id',
        'kind',
        'state',
        'confidence',
        'resolver',
        'evidence',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Transaction, $this> */
    public function fromTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'from_transaction_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function toTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'to_transaction_id');
    }
}
