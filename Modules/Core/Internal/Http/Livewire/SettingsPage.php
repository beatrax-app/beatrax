<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Community\Public\Actions\OpenExternalUrlAction;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Enums\Theme;
use Modules\Core\Public\Support\DriftThresholdOptions;
use Modules\Core\Public\Support\Lang;
use Modules\FX\Public\Actions\DispatchFxRatesRefresh;
use Modules\Ledger\Public\Services\BaseCurrency;

/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final class SettingsPage extends Component
{
    #[Validate('required|in:eur_only,original')]
    public string $defaultCurrencyView = 'eur_only';

    #[Validate('required|integer|min:1|max:28')]
    public int $periodStartDay = 1;

    #[Validate('required|integer|min:2|max:60')]
    public int $recurringDetectionWindowMonths = 2;

    #[Validate('required|integer|min:0|max:100000000')]
    public int $recurringIncomeMinAmountMinor = 200000;

    public int $driftAlertThresholdPercent = 5;

    public string $theme = Theme::DEFAULT;

    // The switcher's "follow the browser" option. A null `users.locale`
    // stores as this sentinel in the form state so the radio group has a
    // concrete value to bind; setLocale() maps it back to null on write.
    private const string LOCALE_AUTO = 'auto';

    // The allow-list is built in rules() from Locale::codes() rather than
    // written out in a #[Validate] attribute: an attribute argument has to be
    // a constant expression, which would freeze the shipped locales into a
    // second list that drifts the moment a language is added.
    public string $locale = self::LOCALE_AUTO;

    #[Validate('boolean')]
    public bool $isDeveloper = false;

    public bool $saved = false;

    #[Validate('nullable|string|size:3|exists:currencies,code')]
    public string $baseCurrency = 'EUR';

    public bool $fxOnlineEnabled = false;

    public bool $fxRefreshing = false;

    public ?string $fxLastUpdated = null;

    public function mount(CurrentUser $currentUser, DatabaseManager $db, BaseCurrency $baseCurrency): void
    {
        $user = $currentUser->user();
        $this->defaultCurrencyView = $user->default_currency_view;
        $this->periodStartDay = $user->period_start_day;
        $this->recurringDetectionWindowMonths = $user->recurring_detection_window_months;
        $this->recurringIncomeMinAmountMinor = $user->recurring_income_min_amount_minor;
        $this->driftAlertThresholdPercent = $user->drift_alert_threshold_percent;
        $this->theme = $user->theme;
        $this->locale = $user->locale ?? self::LOCALE_AUTO;
        $this->isDeveloper = $user->is_developer === true;
        $this->baseCurrency = $user->base_currency ?? $baseCurrency->code();
        $this->fxOnlineEnabled = $user->fx_online_enabled ?? false;

        $latestRate = $db->connection()
            ->table('exchange_rates')
            ->orderByDesc('rate_date')
            ->first(['rate_date']);

        if ($latestRate !== null) {
            $rawDate = $latestRate->rate_date ?? null;
            $this->fxLastUpdated = is_string($rawDate) ? substr($rawDate, 0, 10) : null;
        }
    }

    // Validates the chosen value against the light,dark,system allow-list
    // before the preference write so an out-of-enum value can never reach
    // the users row or the layout's class attribute.
    public function setTheme(string $theme, CurrentUser $currentUser, WriteUserPreference $writeUserPreference): void
    {
        $this->theme = $theme;
        $this->validateOnly('theme');

        ($writeUserPreference)($currentUser->user()->id, ['theme' => $this->theme]);
    }

    // Validates against the supported-locale allow-list before the preference
    // write, so an out-of-enum value never reaches the users row. "auto" persists as NULL
    // (no override → browser detection). The locale is retargeted in the
    // same request too, so the page re-renders in the new language at once.
    public function setLocale(
        string $locale,
        CurrentUser $currentUser,
        WriteUserPreference $writeUserPreference,
        Application $app,
    ): void {
        $this->locale = $locale;
        $this->validateOnly('locale');

        $storedLocale = $this->locale === self::LOCALE_AUTO ? null : $this->locale;

        ($writeUserPreference)($currentUser->user()->id, ['locale' => $storedLocale]);

        // Through the application, not the translator alone: Livewire snapshots
        // `app()->getLocale()` on dehydrate and re-applies it on the next
        // action, so retargeting only the translator re-rendered this page in
        // the new language and then reverted the one after it.
        $app->setLocale($storedLocale ?? Locale::DEFAULT);
    }

    public function setDevMode(bool $value, CurrentUser $currentUser): void
    {
        $this->isDeveloper = $value;
        $this->validateOnly('isDeveloper');

        $user = $currentUser->user();
        $user->fill(['is_developer' => $value])->save();
    }

    // Fallback for users who want to grab an installer manually (first
    // install, or recovery if a future auto-update fails to apply).
    public function openReleasesPage(OpenExternalUrlAction $opener): void
    {
        $opener('https://github.com/beatrax-app/beatrax/releases/latest');
    }

    public function toggleFxOnline(CurrentUser $currentUser, WriteUserPreference $writeUserPreference): void
    {
        $this->fxOnlineEnabled = ! $this->fxOnlineEnabled;

        ($writeUserPreference)($currentUser->user()->id, ['fx_online_enabled' => $this->fxOnlineEnabled]);
    }

    public function refreshFxRates(DispatchFxRatesRefresh $dispatch, CurrentUser $currentUser): void
    {
        $this->fxRefreshing = true;
        $dispatch($currentUser->user()->id);
    }

    public function save(CurrentUser $currentUser): void
    {
        $this->validate();

        $user = $currentUser->user();
        $user->default_currency_view = $this->defaultCurrencyView;
        $user->period_start_day = $this->periodStartDay;
        $user->recurring_detection_window_months = $this->recurringDetectionWindowMonths;
        $user->recurring_income_min_amount_minor = $this->recurringIncomeMinAmountMinor;
        $user->drift_alert_threshold_percent = $this->driftAlertThresholdPercent;
        $user->base_currency = $this->baseCurrency;
        $user->save();

        $this->saved = true;
        $this->dispatch('settings-saved');
    }

    public function render(ViewFactory $views, CurrentUser $currentUser, DatabaseManager $db, BaseCurrency $baseCurrency): View
    {
        // Load every account the user owns (one OpeningBalanceEditor per row)
        // and the seeded currencies for the picker, sorted alpha by code so
        // the <select> is stable. Both queries use the DB connection directly
        // (no facade) per the BoundaryArchTest rule.
        $accounts = $db->connection()->table('accounts')
            ->where('user_id', $currentUser->user()->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'kind',
                'default_currency',
                'forecast_min_buffer_minor',
                'opening_balance_minor',
                'opening_balance_as_of_date',
            ]);

        $currencyRows = $db->connection()
            ->table('currencies')
            ->orderBy('code')
            ->get(['code', 'name']);

        return $views->make('core::livewire.settings-page', [
            // Expose the per-user inbox-drop path so the help text
            // renders the directory the user must actually create
            // (storage/app/inbox-drop/{userId}/) rather than the
            // root inbox-drop folder.
            'userId' => $currentUser->user()->id,
            'forecastingAccounts' => $this->mapAccounts($accounts, $baseCurrency->code()),
            'currencyOptions' => $this->mapCurrencyOptions($currencyRows),
        ]);
    }

    /**
     * @param  Collection<int, \stdClass>  $accounts
     * @return list<array<string, mixed>>
     */
    private function mapAccounts(Collection $accounts, string $baseCurrencyCode): array
    {
        $accountList = [];
        foreach ($accounts as $row) {
            $accountList[] = [
                'id' => is_numeric($row->id ?? null) ? (int) $row->id : 0,
                'name' => is_string($row->name ?? null) ? $row->name : '',
                'kind' => is_string($row->kind ?? null) ? $row->kind : '',
                'default_currency' => is_string($row->default_currency ?? null) ? $row->default_currency : $baseCurrencyCode,
                'forecast_min_buffer_minor' => is_numeric($row->forecast_min_buffer_minor ?? null)
                    ? (int) $row->forecast_min_buffer_minor
                    : null,
                'opening_balance_minor' => is_numeric($row->opening_balance_minor ?? null)
                    ? (int) $row->opening_balance_minor
                    : null,
                'opening_balance_as_of_date' => $this->resolveAsOfDate($row->opening_balance_as_of_date ?? null),
            ];
        }

        return $accountList;
    }

    private function resolveAsOfDate(mixed $rawAsOf): ?string
    {
        if ($rawAsOf instanceof \DateTimeInterface) {
            return $rawAsOf->format('Y-m-d');
        }

        return is_string($rawAsOf) && $rawAsOf !== '' ? substr($rawAsOf, 0, 10) : null;
    }

    /**
     * @param  Collection<int, \stdClass>  $currencyRows
     * @return array<string, string>
     */
    private function mapCurrencyOptions(Collection $currencyRows): array
    {
        $currencyOptions = [];
        foreach ($currencyRows as $row) {
            $code = is_string($row->code ?? null) ? $row->code : '';
            $name = is_string($row->name ?? null) ? $row->name : '';
            if ($code !== '') {
                $currencyOptions[$code] = $name;
            }
        }

        return $currencyOptions;
    }

    // The theme and drift-threshold allow-lists are built from their shared
    // SSoT (the Theme enum, the DriftThresholdOptions constant) rather than
    // inline in: literals — a #[Validate] attribute cannot reference a
    // runtime value. Livewire composes this with the attribute rules.
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'theme' => 'required|in:'.implode(',', Theme::values()),
            'locale' => 'required|in:'.self::LOCALE_AUTO.','.implode(',', Locale::codes()),
            'driftAlertThresholdPercent' => 'required|integer|in:'.implode(',', DriftThresholdOptions::PERCENTS),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        // Pulled through Lang::get so validation copy translates with the
        // rest of the page; each rule variant maps to one core::settings.errors
        // key. Resolved per call so a mid-session language switch is reflected.
        $periodDay = Lang::get('core::settings.errors.period_day');
        $windowMonths = Lang::get('core::settings.errors.window_months');
        $amount = Lang::get('core::settings.errors.amount');
        $threshold = Lang::get('core::settings.errors.threshold');
        $currencyRequired = Lang::get('core::settings.errors.currency_required');
        $currencyView = Lang::get('core::settings.errors.currency_view');

        return [
            'periodStartDay.required' => $periodDay,
            'periodStartDay.integer' => $periodDay,
            'periodStartDay.min' => $periodDay,
            'periodStartDay.max' => $periodDay,
            'defaultCurrencyView.required' => $currencyView,
            'defaultCurrencyView.in' => $currencyView,
            'recurringDetectionWindowMonths.required' => $windowMonths,
            'recurringDetectionWindowMonths.integer' => $windowMonths,
            'recurringDetectionWindowMonths.min' => $windowMonths,
            'recurringDetectionWindowMonths.max' => $windowMonths,
            'recurringIncomeMinAmountMinor.required' => $amount,
            'recurringIncomeMinAmountMinor.integer' => $amount,
            'recurringIncomeMinAmountMinor.min' => $amount,
            'recurringIncomeMinAmountMinor.max' => $amount,
            'driftAlertThresholdPercent.required' => $threshold,
            'driftAlertThresholdPercent.integer' => $threshold,
            'driftAlertThresholdPercent.in' => $threshold,
            'baseCurrency.size' => $currencyRequired,
            'baseCurrency.exists' => $currencyRequired,
            'baseCurrency.string' => $currencyRequired,
        ];
    }
}
