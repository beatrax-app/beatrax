<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Modules\Core\Public\Concerns\BelongsToUser;
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
 * @property string $source_format
 * @property int $import_run_id
 * @property int $source_row_index
 * @property string|null $source_ref
 * @property string $fingerprint
 * @property int $fingerprint_version
 * @property string $status
 */
final class Transaction extends Model
{
    use BelongsToUser;

    /**
     * Allowed transaction-type values (LED-02). The DB CHECK constraint cannot
     * be added via SQLite ALTER TABLE, so the model enforces the list in
     * `booted()` for any Eloquent-driven create / save, and `RecordTransactions`
     * re-asserts the same list before its `insertOrIgnore` write path.
     *
     * @var list<string>
     */
    public const TYPES = ['expense', 'income', 'transfer_out', 'transfer_in', 'fee', 'refund', 'adjustment'];

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'account_id', 'type',
        'posted_at', 'booked_at', 'value_date',
        'amount_minor', 'currency',
        'settled_amount_minor', 'settled_currency', 'fx_rate_used',
        'counterparty_name', 'counterparty_iban', 'counterparty_normalized', 'normalization_version',
        'description', 'category_id',
        'source_format', 'import_run_id', 'source_row_index', 'source_ref',
        'fingerprint', 'fingerprint_version',
        'status',
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
     * @return BelongsTo<ImportRun, $this>
     */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }

    protected static function booted(): void
    {
        self::creating(static function (Transaction $tx): void {
            if (! in_array($tx->type, self::TYPES, true)) {
                throw new InvalidArgumentException(
                    "Invalid transaction type: '{$tx->type}'. Allowed: ".implode(', ', self::TYPES),
                );
            }
        });
    }
}
