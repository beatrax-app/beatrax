<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use ArrayObject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Casts\DateOnlyCast;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Counterparties\Models\Counterparty;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Internal\Casts\MoneyMinorCast;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Sync\Public\Casts\EncryptedJsonCast;

/**
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
 * @property string|null $note
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

    // Unlike the transaction type, status has no DB-layer trigger guarding it;
    // this is what the write paths that take a raw string validate against.
    /** @var list<string> */
    public const array STATUSES = [
        ClearedStatus::Uncleared->value,
        ClearedStatus::Cleared->value,
        ClearedStatus::Reconciled->value,
    ];

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
            'posted_at' => DateOnlyCast::class,
            'booked_at' => 'immutable_datetime',
            'value_date' => DateOnlyCast::class,
            'amount_minor' => 'integer',
            'settled_amount_minor' => 'integer',
            'normalization_version' => 'integer',
            'fingerprint_version' => 'integer',
            'source_row_index' => 'integer',
            // SQLite hands this back as a float, and brick/math 0.18 converts a
            // float argument to int — 0.92917629 silently becomes 0, collapsing
            // every rate recomputed from it to zero.
            'fx_rate_used' => 'string',
            'raw_payload' => EncryptedJsonCast::class,
            'auto_category_provenance' => 'array',
            'enriched_from' => AsArrayObject::class,
            'payment_type' => PaymentType::class,
            // `amount` and `settled_amount` are not columns; the cast bridges
            // each to its (minor, currency) pair.
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

    // Null on rows predating the resolver, self-account rows, rows with no
    // IBAN/name/description to resolve from, and rows the GC has pruned.
    /**
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

    // Only set on a transfer_out/transfer_in row whose cross-account
    // counterpart was also imported and matched.
    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function pair(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'pair_transaction_id');
    }
}
