<?php

declare(strict_types=1);

namespace Modules\Chains\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Ledger\Models\Transaction;

/**
 * Eloquent model for the chain_links table — the cross-source funding-
 * chain ledger.
 *
 * One row links a downstream charge (`from_transaction_id`) to its
 * funder (`to_transaction_id`). Allowed kinds: `paypal_funding` and
 * `ics_bulk_settle`. Allowed states: `candidate`, `confirmed`,
 * `rejected`. The DB-layer BEFORE INSERT / BEFORE UPDATE triggers
 * reject any value outside those sets regardless of write path.
 *
 * `evidence` is a JSON column carrying resolver-emitted structured
 * data (signature_hash, tolerance_used, unaccounted_delta_minor,
 * statement_id, ...). Cast as `array` so it round-trips through
 * Eloquent without manual encode/decode at the call site.
 *
 * `confidence` is intentionally left without an explicit cast — the
 * SQLite decimal column returns a string and the strict-rules cast
 * lint stays happy. Callers reading the value cast to (float) at the
 * boundary if a numeric comparison is required.
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
