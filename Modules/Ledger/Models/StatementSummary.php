<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;

// One row per import_run, and only for sources carrying statement-level
// metadata: CAMT.053 and MT940 always do, CSV never does.
/**
 * @property int $id
 * @property int|null $user_id
 * @property int $import_run_id
 * @property int $account_id
 * @property string $iban_owner
 * @property string|null $statement_number
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property int|null $opening_balance_minor
 * @property string|null $opening_balance_currency
 * @property CarbonImmutable|null $opening_balance_date
 * @property int|null $closing_balance_minor
 * @property string|null $closing_balance_currency
 * @property CarbonImmutable|null $closing_balance_date
 * @property CarbonImmutable|null $payment_due_date
 * @property int $entry_count
 * @property array<string, mixed>|null $extras
 */
final class StatementSummary extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'import_run_id',
        'account_id',
        'iban_owner',
        'statement_number',
        'period_start',
        'period_end',
        'opening_balance_minor',
        'opening_balance_currency',
        'opening_balance_date',
        'closing_balance_minor',
        'closing_balance_currency',
        'closing_balance_date',
        'payment_due_date',
        'entry_count',
        'extras',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'opening_balance_date' => 'immutable_datetime',
            'closing_balance_date' => 'immutable_datetime',
            'payment_due_date' => 'immutable_datetime',
            'opening_balance_minor' => 'integer',
            'closing_balance_minor' => 'integer',
            'entry_count' => 'integer',
            'extras' => 'array',
        ];
    }

    /** @return BelongsTo<ImportRun, $this> */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
