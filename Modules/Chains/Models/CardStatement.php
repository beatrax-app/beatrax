<?php

declare(strict_types=1);

namespace Modules\Chains\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

/**
 * @link ../../../.docs/features/chains/card-statement-lifecycle.md
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $account_id
 * @property int|null $import_run_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property int $total_amount_minor
 * @property int $open_balance_minor
 * @property string $currency
 * @property string $state
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CardStatement extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'account_id',
        'import_run_id',
        'period_start',
        'period_end',
        'total_amount_minor',
        'open_balance_minor',
        'currency',
        'state',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'total_amount_minor' => 'integer',
            'open_balance_minor' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<ImportRun, $this> */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }
}
