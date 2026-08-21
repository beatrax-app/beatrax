<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Ledger\Internal\Casts\MoneyMinorCast;

// A transaction with >= 2 legs is a split parent: category roll-ups count its
// legs instead of its own settled_amount_minor, and the legs sum to it exactly
// — enforced by SaveTransactionSplit, never a DB CHECK.
/**
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
