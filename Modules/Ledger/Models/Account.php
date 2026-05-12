<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Local financial account: ASN bank account, ICS credit card, PayPal wallet,
 * etc. Identified by IBAN where applicable.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property string $slug
 * @property string $kind
 * @property string $iban
 * @property string $default_currency
 */
final class Account extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = ['user_id', 'name', 'slug', 'kind', 'iban', 'default_currency'];

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
