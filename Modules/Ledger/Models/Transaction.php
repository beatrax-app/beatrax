<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use ArrayObject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Counterparties\Models\Counterparty;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Internal\Casts\MoneyMinorCast;

/**
 * The canonical row in the ledger. One row per posted bank/card movement,
 * keyed by a composite-UNIQUE fingerprint that makes idempotent re-imports
 * a no-op at the DB layer.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $account_id
 * @property string $type
 * @property CarbonImmutable $posted_at
 * @property CarbonImmutable $booked_at
 * @property CarbonImmutable $value_date
 * @property int $amount_minor
 * @property string $currency
 * @property int $settled_amount_minor
 * @property string $settled_currency
 * @property string|null $fx_rate_used
 * @property string|null $counterparty_name
 * @property string|null $counterparty_iban
 * @property string $counterparty_normalized
 * @property int $normalization_version
 * @property string|null $description
 * @property int|null $category_id
 * @property int|null $counterparty_id
 * @property array<string, mixed>|null $auto_category_provenance
 * @property string $source_format
 * @property int $import_run_id
 * @property int $source_row_index
 * @property string|null $source_ref
 * @property array<int|string, mixed>|null $raw_payload
 * @property ArrayObject<int, array<string, mixed>>|null $enriched_from
 * @property int|null $pair_transaction_id
 * @property string $fingerprint
 * @property int $fingerprint_version
 * @property string $status
 * @property PaymentType $payment_type
 */
final class Transaction extends Model
{
    use BelongsToUser;

    /**
     * Allowed transaction-type values. The DB-layer BEFORE INSERT / BEFORE
     * UPDATE triggers on `transactions` reject any value outside this list
     * regardless of write path (Eloquent or raw insertOrIgnore), so this
     * constant is the authoritative human-readable reference. The migration
     * mirrors the same list inside the trigger body — keep them in sync.
     *
     * @var list<string>
     */
    public const TYPES = ['expense', 'income', 'transfer_out', 'transfer_in', 'fee', 'refund', 'adjustment'];

    /**
     * Allowed reconciliation-status values (D-01/D-02, Phase 13.3). The
     * three-state lifecycle `uncleared -> cleared -> reconciled` is the
     * single app-level allow-list every Phase 13.3 write path (the D-03
     * import default, the per-row toggle, `ReconciliationWriter`)
     * validates against. Unlike `TYPES`, `status` carries no DB-layer
     * BEFORE INSERT/UPDATE trigger — `status string(16) default 'cleared'`
     * is otherwise unconstrained — so this constant is the sole source of
     * truth for the enum.
     *
     * @var list<string>
     */
    public const STATUSES = ['uncleared', 'cleared', 'reconciled'];

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'account_id', 'type',
        'posted_at', 'booked_at', 'value_date',
        'amount_minor', 'currency',
        'settled_amount_minor', 'settled_currency', 'fx_rate_used',
        'counterparty_name', 'counterparty_iban', 'counterparty_normalized', 'normalization_version',
        'description', 'category_id', 'counterparty_id', 'auto_category_provenance',
        'source_format', 'import_run_id', 'source_row_index', 'source_ref',
        'raw_payload',
        'enriched_from',
        'pair_transaction_id',
        'fingerprint', 'fingerprint_version',
        'status',
        'payment_type',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'posted_at' => 'immutable_date',
            'booked_at' => 'immutable_datetime',
            'value_date' => 'immutable_date',
            'amount_minor' => 'integer',
            'settled_amount_minor' => 'integer',
            'normalization_version' => 'integer',
            'fingerprint_version' => 'integer',
            'source_row_index' => 'integer',
            'raw_payload' => 'array',
            'auto_category_provenance' => 'array',
            'enriched_from' => AsArrayObject::class,
            'payment_type' => PaymentType::class,
            // Virtual Money attributes — `amount` and `settled_amount` are not
            // real columns; the cast bridges them to the (minor, currency) pair.
            'amount' => MoneyMinorCast::class,
            'settled_amount' => MoneyMinorCast::class.':settled_amount_minor,settled_currency',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The resolved counterparty for this transaction. Populated by the
     * `ResolveCounterpartyStage` Import-pipeline stage (Plan 17-05b) via
     * the `CounterpartyResolver` 7-step chain. NULL for rows that pre-
     * date the resolver, rows the resolver couldn't materialise (self-
     * account branch, or pathological rows carrying no IBAN / name /
     * description), or rows whose linked counterparty was pruned by the
     * future garbage-collector job.
     *
     * Consumers rendering counterparty-name affordances (the four
     * transaction-row surfaces in Ledger / Categorization / Chains)
     * eager-load this relation via `->with('counterparty')` to prevent
     * N+1 query expansion on list-render paths.
     *
     * @return BelongsTo<Counterparty, $this>
     */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    /**
     * @return BelongsTo<ImportRun, $this>
     */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }

    /**
     * The paired-partner relationship. Populated for `transfer_out` /
     * `transfer_in` rows whose cross-account counterpart landed in the
     * ledger and was matched by the deterministic Layer-1 listener.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function pair(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'pair_transaction_id');
    }
}
