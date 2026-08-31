<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\CurrencyView;

/**
 * @property int $id
 * @property string $username
 * @property bool $is_developer
 * @property bool $force_password_change_at_next_login
 * @property string $password
 * @property int $period_start_day
 * @property CurrencyView $default_currency_view
 * @property ?string $base_currency
 * @property bool $fx_online_enabled
 * @property bool|null $auto_import_drop_folder
 * @property string $receipt_conflict_resolution
 * @property int $recurring_detection_window_months
 * @property int $recurring_income_min_amount_minor
 * @property int $drift_alert_threshold_percent
 * @property int $anomaly_sensitivity_percent
 * @property int $anomaly_min_amount_minor
 * @property Carbon|null $anomaly_backfilled_at
 * @property string $theme
 * @property string|null $locale
 * @property string|null $close_behavior
 * @property array<string, mixed>|null $community_settings
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class User extends Authenticatable
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    use Notifiable;

    // Quoted back to the reader in the recurring-detection help text, which is
    // why it is a named constant rather than a bare default: the copy reads it
    // rather than restating it in twenty-six languages.
    public const int DEFAULT_RECURRING_INCOME_MIN_AMOUNT_MINOR = 200000;

    /** @var list<string> */
    protected $fillable = [
        'username',
        'is_developer',
        'force_password_change_at_next_login',
        'password',
        'period_start_day',
        'default_currency_view',
        'base_currency',
        'fx_online_enabled',
        'auto_import_drop_folder',
        'receipt_conflict_resolution',
        'recurring_detection_window_months',
        'recurring_income_min_amount_minor',
        'drift_alert_threshold_percent',
        'anomaly_sensitivity_percent',
        'anomaly_min_amount_minor',
        'anomaly_backfilled_at',
        'theme',
        'locale',
        'close_behavior',
        'community_settings',
    ];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'recurring_detection_window_months' => 2,
        'recurring_income_min_amount_minor' => self::DEFAULT_RECURRING_INCOME_MIN_AMOUNT_MINOR,
        'drift_alert_threshold_percent' => 5,
        'anomaly_sensitivity_percent' => 50,
        'anomaly_min_amount_minor' => 1000,
        'theme' => 'system',
        'base_currency' => Currency::Eur->value,
        // create() does not read the row back, so without this a just-created
        // model carries null where the column's DB default is BaseOnly, and
        // every reader of the fresh instance saw the other view.
        'default_currency_view' => CurrencyView::BaseOnly->value,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_developer' => 'boolean',
            'force_password_change_at_next_login' => 'boolean',
            'period_start_day' => 'integer',
            'default_currency_view' => CurrencyView::class,
            'base_currency' => 'string',
            'fx_online_enabled' => 'boolean',
            'auto_import_drop_folder' => 'boolean',
            'recurring_detection_window_months' => 'integer',
            'recurring_income_min_amount_minor' => 'integer',
            'drift_alert_threshold_percent' => 'integer',
            'anomaly_sensitivity_percent' => 'integer',
            'anomaly_min_amount_minor' => 'integer',
            'anomaly_backfilled_at' => 'immutable_datetime',
            'theme' => 'string',
            'locale' => 'string',
            'close_behavior' => 'string',
            'community_settings' => 'array',
        ];
    }
}
