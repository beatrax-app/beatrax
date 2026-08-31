<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Public\Casts\DateOnlyCast;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property string $slug
 * @property string $kind
 * @property string $iban
 * @property string $default_currency
 * @property int|null $starting_balance_minor
 * @property CarbonImmutable|null $starting_balance_date
 * @property CarbonImmutable|null $opening_balance_as_of_date
 */
final class Account extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'kind',
        'iban',
        'default_currency',
        'starting_balance_minor',
        'starting_balance_date',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starting_balance_minor' => 'integer',
            'starting_balance_date' => DateOnlyCast::class,
            'opening_balance_as_of_date' => DateOnlyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency', 'code');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
