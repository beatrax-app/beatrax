<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Community\Public\Actions\OpenExternalUrlAction;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\FX\Public\Actions\DispatchFxRatesRefresh;
use Modules\Ledger\Models\Currency;

/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final class SettingsPage extends Component
{
    #[Validate('required|in:eur_only,original')]
    public string $defaultCurrencyView = 'eur_only';

    #[Validate('required|integer|min:1|max:28')]
    public int $periodStartDay = 1;

    public bool $autoImportFromDropFolder = false;

    #[Validate('required|integer|min:2|max:60')]
    public int $recurringDetectionWindowMonths = 2;

    #[Validate('required|integer|min:0|max:100000000')]
    public int $recurringIncomeMinAmountMinor = 200000;

    #[Validate('required|integer|in:1,2,5,10,25,50')]
    public int $driftAlertThresholdPercent = 5;

    #[Validate('required|in:light,dark,system')]
    public string $theme = 'system';

    #[Validate('boolean')]
    public bool $isDeveloper = false;

    public bool $saved = false;

    #[Validate('nullable|string|size:3|exists:currencies,code')]
    public string $baseCurrency = 'EUR';

    public bool $fxOnlineEnabled = false;

    public bool $fxRefreshing = false;

    public ?string $fxLastUpdated = null;

    public function mount(CurrentUser $currentUser, DatabaseManager $db): void
    {
        $user = $currentUser->user();
        $this->defaultCurrencyView = $user->default_currency_view;
        $this->periodStartDay = $user->period_start_day;
        $this->autoImportFromDropFolder = (bool) $user->auto_import_drop_folder;
        $this->recurringDetectionWindowMonths = $user->recurring_detection_window_months;
        $this->recurringIncomeMinAmountMinor = $user->recurring_income_min_amount_minor;
        $this->driftAlertThresholdPercent = $user->drift_alert_threshold_percent;
        $this->theme = $user->theme;
        $this->isDeveloper = $user->is_developer === true;
        $this->baseCurrency = $user->base_currency ?? 'EUR';
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
    // before the raw query-builder write so an out-of-enum value can never
    // reach the users row or the layout's class attribute.
    public function setTheme(string $theme, CurrentUser $currentUser, DatabaseManager $db, Clock $clock): void
    {
        $this->theme = $theme;
        $this->validateOnly('theme');

        $user = $currentUser->user();
        $db->connection()
            ->table('users')
            ->where('id', $user->id)
            ->update([
                'theme' => $this->theme,
                'updated_at' => $clock->now()->toDateTimeString(),
            ]);
    }

    public function setDevMode(bool $value, CurrentUser $currentUser): void
    {
        $this->isDeveloper = $value;
        $this->validateOnly('isDeveloper');

        $user = $currentUser->user();
        $user->fill(['is_developer' => $value])->save();
    }

    // The Blade view binds the checkbox via wire:change only (no
    // wire:model.live), so this single round-trip covers both the property
    // update and the DB write, avoiding a double round-trip.
    public function toggleAutoImport(CurrentUser $currentUser, DatabaseManager $db, Clock $clock): void
    {
        $this->autoImportFromDropFolder = ! $this->autoImportFromDropFolder;

        $user = $currentUser->user();
        $db->connection()
            ->table('users')
            ->where('id', $user->id)
            ->update([
                'auto_import_drop_folder' => $this->autoImportFromDropFolder,
                'updated_at' => $clock->now()->toDateTimeString(),
            ]);
    }

    // Fallback for users who want to grab an installer manually (first
    // install, or recovery if a future auto-update fails to apply).
    public function openReleasesPage(OpenExternalUrlAction $opener): void
    {
        $opener('https://github.com/nightworksio/beatrax/releases/latest');
    }

    public function toggleFxOnline(CurrentUser $currentUser, DatabaseManager $db, Clock $clock): void
    {
        $this->fxOnlineEnabled = ! $this->fxOnlineEnabled;

        $user = $currentUser->user();
        $db->connection()
            ->table('users')
            ->where('id', $user->id)
            ->update([
                'fx_online_enabled' => $this->fxOnlineEnabled,
                'updated_at' => $clock->now()->toDateTimeString(),
            ]);
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

    public function render(ViewFactory $views, CurrentUser $currentUser, DatabaseManager $db): View
    {
        // Load every account the user owns so the Forecasting section
        // can mount one OpeningBalanceEditor per row.
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

        $accountList = [];
        foreach ($accounts as $row) {
            /** @var \stdClass $row */
            $rawAsOf = $row->opening_balance_as_of_date ?? null;
            $asOf = null;
            if ($rawAsOf instanceof \DateTimeInterface) {
                $asOf = $rawAsOf->format('Y-m-d');
            } elseif (is_string($rawAsOf) && $rawAsOf !== '') {
                $asOf = substr($rawAsOf, 0, 10);
            }
            $accountList[] = [
                'id' => is_numeric($row->id ?? null) ? (int) $row->id : 0,
                'name' => is_string($row->name ?? null) ? $row->name : '',
                'kind' => is_string($row->kind ?? null) ? $row->kind : '',
                'default_currency' => is_string($row->default_currency ?? null) ? $row->default_currency : 'EUR',
                'forecast_min_buffer_minor' => is_numeric($row->forecast_min_buffer_minor ?? null)
                    ? (int) $row->forecast_min_buffer_minor
                    : null,
                'opening_balance_minor' => is_numeric($row->opening_balance_minor ?? null)
                    ? (int) $row->opening_balance_minor
                    : null,
                'opening_balance_as_of_date' => $asOf,
            ];
        }

        // Build the currency picker option list from the seeded currencies table.
        // Sorted alpha by code so the <select> is consistent across renders.
        // The query uses the DB connection directly (no facade) per the
        // BoundaryArchTest no-facade rule.
        $currencyRows = $db->connection()
            ->table('currencies')
            ->orderBy('code')
            ->get(['code', 'name']);

        /** @var array<string, string> $currencyOptions */
        $currencyOptions = [];
        foreach ($currencyRows as $row) {
            /** @var \stdClass $row */
            $code = is_string($row->code ?? null) ? $row->code : '';
            $name = is_string($row->name ?? null) ? $row->name : '';
            if ($code !== '') {
                $currencyOptions[$code] = $name;
            }
        }

        return $views->make('core::livewire.settings-page', [
            // Expose the per-user inbox-drop path so the help text
            // renders the directory the user must actually create
            // (storage/app/inbox-drop/{userId}/) rather than the
            // root inbox-drop folder.
            'userId' => $currentUser->user()->id,
            'forecastingAccounts' => $accountList,
            'currencyOptions' => $currencyOptions,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'periodStartDay.required' => 'Choose a day from 1 to 28.',
            'periodStartDay.integer' => 'Choose a day from 1 to 28.',
            'periodStartDay.min' => 'Choose a day from 1 to 28.',
            'periodStartDay.max' => 'Choose a day from 1 to 28.',
            'defaultCurrencyView.required' => 'Pick one of the available options.',
            'defaultCurrencyView.in' => 'Pick one of the available options.',
            'recurringDetectionWindowMonths.required' => 'Choose between 2 and 60 months.',
            'recurringDetectionWindowMonths.integer' => 'Choose between 2 and 60 months.',
            'recurringDetectionWindowMonths.min' => 'Choose between 2 and 60 months.',
            'recurringDetectionWindowMonths.max' => 'Choose between 2 and 60 months.',
            'recurringIncomeMinAmountMinor.required' => 'Enter an amount from €0 upward.',
            'recurringIncomeMinAmountMinor.integer' => 'Enter an amount from €0 upward.',
            'recurringIncomeMinAmountMinor.min' => 'Enter an amount from €0 upward.',
            'recurringIncomeMinAmountMinor.max' => 'Enter an amount from €0 upward.',
            'driftAlertThresholdPercent.required' => 'Choose a threshold from 1%, 2%, 5%, 10%, 25%, or 50%.',
            'driftAlertThresholdPercent.integer' => 'Choose a threshold from 1%, 2%, 5%, 10%, 25%, or 50%.',
            'driftAlertThresholdPercent.in' => 'Choose a threshold from 1%, 2%, 5%, 10%, 25%, or 50%.',
            'baseCurrency.size' => 'Please choose a currency.',
            'baseCurrency.exists' => 'Please choose a currency.',
            'baseCurrency.string' => 'Please choose a currency.',
        ];
    }
}
