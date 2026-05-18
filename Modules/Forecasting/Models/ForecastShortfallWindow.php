<?php

declare(strict_types=1);

namespace Modules\Forecasting\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Forecasting\Database\Factories\ForecastShortfallWindowFactory;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * Eloquent model for the forecast_shortfall_windows table — one row
 * models one contiguous (starts_at, ends_at) window during which the
 * projected balance dipped below the effective per-account buffer.
 *
 * `lowest_balance_minor` is the signed bottom point of the window.
 * `buffer_used_minor` is the effective buffer captured at detection
 * time — Phase 9's honest-audit precedent so a later buffer edit
 * cannot silently rewrite the historical shortfall narrative.
 *
 * `scenario_id` is nullable: NULL = baseline projection's shortfall;
 * non-NULL = scenario projection's shortfall.
 *
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property int|null $scenario_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property int $lowest_balance_minor
 * @property string $currency
 * @property int $buffer_used_minor
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Account $account
 * @property-read ForecastScenario|null $scenario
 */
final class ForecastShortfallWindow extends Model
{
    use BelongsToUser;

    /** @use HasFactory<ForecastShortfallWindowFactory> */
    use HasFactory;

    protected $table = 'forecast_shortfall_windows';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'account_id',
        'scenario_id',
        'starts_at',
        'ends_at',
        'lowest_balance_minor',
        'currency',
        'buffer_used_minor',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_date',
            'ends_at' => 'immutable_date',
            'lowest_balance_minor' => 'integer',
            'buffer_used_minor' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<ForecastScenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(ForecastScenario::class, 'scenario_id');
    }

    /**
     * Returns the lowest projected balance as a Money value. The
     * underlying column is signed — a negative result indicates the
     * account went into overdraft during the window.
     */
    public function lowestBalance(): Money
    {
        return Money::ofMinor($this->lowest_balance_minor, $this->currency);
    }

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return ForecastShortfallWindowFactory::new();
    }
}
