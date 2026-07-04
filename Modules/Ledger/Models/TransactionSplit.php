<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Ledger\Internal\Casts\MoneyMinorCast;

/**
 * One "leg" of a split transaction (Phase 13.1). A transaction with ≥2
 * TransactionSplit rows is a split parent; its settled_amount_minor is
 * excluded from category-sum roll-ups in favour of its legs (D-02/D-08).
 *
 * `category_id` is REQUIRED (unlike Transaction::category_id) — Req 5.
 * `settled_amount_minor`/`settled_currency` always share the parent
 * transaction's settled currency (D-04b); the sum of every leg's
 * settled_amount_minor equals the parent's settled_amount_minor exactly,
 * enforced by SaveTransactionSplit (never a DB CHECK — D-04).
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $transaction_id
 * @property int $category_id
 * @property int $settled_amount_minor
 * @property string $settled_currency
 * @property string|null $note
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class TransactionSplit extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'transaction_id', 'category_id',
        'settled_amount_minor', 'settled_currency', 'note', 'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'settled_amount_minor' => 'integer',
            'sort_order' => 'integer',
            // Virtual Money attribute — `settled_amount` is not a real
            // column; the cast bridges it to the (minor, currency) pair.
            'settled_amount' => MoneyMinorCast::class.':settled_amount_minor,settled_currency',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
