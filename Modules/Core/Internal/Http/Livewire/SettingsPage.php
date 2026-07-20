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
 * Settings page. Surfaces the user preferences that govern global
 * dashboard behaviour:
 *
 *  - `defaultCurrencyView` — chooses the default `/transactions` and
 *    dashboard presentation between EUR-only (settled-EUR pair only) and
 *    Original currency (native pair with settled secondary line). The per-
 *    page toggle on /transactions overrides this default via the
 *    `?currency=` query parameter; the global default lives on the users
 *    row so it survives across sessions and devices.
 *  - `periodStartDay` — the day of the month at which the "this period"
 *    window rolls over. Numbered 1..28 so every calendar month including
 *    February has a valid value. Salary-aligned users typically pick 25.
 *  - `recurringDetectionWindowMonths` — how many months of history the
 *    recurring-series detector scans on each sweep. Bounded 3..60.
 *  - `recurringIncomeMinAmountMinor` — incomes whose absolute amount is
 *    below this threshold are not auto-clustered into income series.
 *    Stored as signed BIGINT minor units; 0 disables the threshold.
 *  - `baseCurrency` — the ISO 4217 reporting currency for all roll-ups
 *    (net worth, dashboard totals, budgets, forecasts). Saved with the
 *    batch Save button; validated against the seeded currencies table.
 *  - `fxOnlineEnabled` — opt-in toggle for online exchange-rate fetch.
 *    Persists instantly (no Save button); defaults false.
 *  - `refreshFxRates()` — dispatches FetchFxRatesJob for the current user
 *    on demand.
 *
 * Service collaborators arrive as parameters on each action method; the
 * Livewire strict-rules ruleset forbids constructor-DI on Component
 * subclasses, so CurrentUser / ViewFactory are resolved per call. The
 * authenticated user is read exclusively from CurrentUser — never from
 * a request-supplied user_id — so cross-user writes are structurally
 * impossible.
 */
final class SettingsPage extends Component
{
    #[Validate('required|in:eur_only,original')]
    public string $defaultCurrencyView = 'eur_only';

    #[Validate('required|integer|min:1|max:28')]
    public int $periodStartDay = 1;

    /**
     * Watched-folder secondary path toggle. When on,
     * ScanInboxDropFolderJob runs every 5 minutes for this user and
     * imports any .eml / .mbox files in
     * storage/app/inbox-drop/{userId}/ through the same matcher
     * pipeline as the wizard upload path. Default off so the wizard
     * remains the documented primary entrypoint.
     */
    public bool $autoImportFromDropFolder = false;

    /**
     * History window (in months) the recurring-series detector scans
     * on each sweep. The lower bound of 2 months keeps monthly
     * detection viable (the detector's MIN_OCCURRENCES gate is 2, so
     * two months of monthly data is the smallest signal the engine
     * can act on); the upper bound of 60 months caps the sweep cost
     * so very long histories still finish in reasonable time.
     */
    #[Validate('required|integer|min:2|max:60')]
    public int $recurringDetectionWindowMonths = 2;

    /**
     * Lower-bound income amount, in signed BIGINT minor units, below
     * which incoming transactions are not auto-clustered into income
     * series. Setting the value to 0 disables the threshold. The
     * upper bound caps unrealistic inputs at €1,000,000.00.
     */
    #[Validate('required|integer|min:0|max:100000000')]
    public int $recurringIncomeMinAmountMinor = 200000;

    /**
     * Global default drift-alert threshold (percent). DriftEvaluator's
     * effective-threshold rule resolves a per-series override first;
     * when null, this user-level value applies; when it falls back to
     * the hard 5% default. Allowed values mirror the six popover
     * options elsewhere in the UI (1 / 2 / 5 / 10 / 25 / 50).
     */
    #[Validate('required|integer|in:1,2,5,10,25,50')]
    public int $driftAlertThresholdPercent = 5;

    /**
     * Appearance preference governing the dark-mode class on `<html>`.
     * One of `light` / `dark` / `system`; `system` follows the operating
     * system. Persisted instant-apply via setTheme() — the Appearance
     * section has no Save button, mirroring the auto-import toggle.
     */
    #[Validate('required|in:light,dark,system')]
    public string $theme = 'system';

    /**
     * Developer Mode toggle. When on, the in-app Developer Console
     * at /dev/* renders for this user. Persisted instant-apply via
     * setDevMode() — toggling writes users.is_developer directly
     * through the Eloquent model so the flip survives
     * logout/login. The setting is per-user; partner accounts
     * cannot toggle each other's flag because the writer always
     * scopes to CurrentUser.
     */
    #[Validate('boolean')]
    public bool $isDeveloper = false;

    /**
     * Inline "Saved." confirmation flag flipped by save() and consumed by
     * the Blade view via `@if ($saved)` + `wire:transition.duration.4000ms`
     * so the confirmation auto-dismisses after four seconds.
     */
    public bool $saved = false;

    /**
     * Base reporting currency for whole-app roll-ups (net worth, dashboard
     * totals, budgets, forecasts). Validated against the seeded currencies
     * table (exists:currencies,code) so arbitrary codes can never reach the
     * DB. Saved with the batch Save button (deferred wire:model), consistent
     * with defaultCurrencyView and periodStartDay.
     *
     * When base_currency equals the figure's currency, zero conversion overhead applies.
     */
    #[Validate('nullable|string|size:3|exists:currencies,code')]
    public string $baseCurrency = 'EUR';

    /**
     * Opt-in toggle for online exchange-rate fetch. Off by default since
     * outbound fetch requires explicit user consent. Persists instantly
     * via toggleFxOnline() without a Save round-trip — mirrors the
     * autoImportFromDropFolder pattern.
     */
    public bool $fxOnlineEnabled = false;

    /**
     * UI flag set to true immediately when refreshFxRates() dispatches the
     * job. Consumed by the Blade view to disable the "Refresh now" button
     * and display "Refreshing…" copy while the round-trip completes.
     */
    public bool $fxRefreshing = false;

    /**
     * Human-readable date of the latest exchange_rates row for this user,
     * hydrated in mount() from the exchange_rates table. Null when no rates
     * have been fetched yet. Displayed in the toggle help text as
     * "Last updated: {date} from {source}." (UI-SPEC §7.1).
     */
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

    /**
     * Instant-apply theme control — mirrors the watched-folder toggle's
     * "no Save button" posture (the segmented control is its own
     * commit). The Blade view calls `setTheme('light'|'dark'|'system')`
     * directly; the handler validates the chosen value against the
     * `light,dark,system` allow-list before the raw query-builder write
     * so an out-of-enum value can never reach the users row or the
     * layout's class attribute.
     */
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

    /**
     * Instant-apply Developer Mode toggle. Writes the boolean to
     * users.is_developer via the Eloquent model so the flip
     * survives logout/login. Scopes the write to the CurrentUser's
     * own row — cross-user writes are structurally impossible
     * because the user resolves through the CurrentUser contract,
     * not a request-supplied id. Mirrors setTheme()'s "no Save
     * button" posture: toggling is its own commit.
     */
    public function setDevMode(bool $value, CurrentUser $currentUser): void
    {
        $this->isDeveloper = $value;
        $this->validateOnly('isDeveloper');

        $user = $currentUser->user();
        $user->fill(['is_developer' => $value])->save();
    }

    /**
     * Instant-apply toggle for the watched-folder secondary path —
     * mirrors the currency-display section's "no Save button"
     * posture (the toggle is its own commit). The Blade view binds
     * the checkbox via `wire:change="toggleAutoImport"` only (no
     * `wire:model.live`); the handler flips the property explicitly
     * so a single round-trip covers both the property update and the
     * DB write. Avoids the double round-trip the combined
     * model.live + change binding would emit.
     */
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

    /**
     * Opens the public GitHub releases page in the user's system
     * browser via the existing OpenExternalUrlAction (https +
     * github.com allow-list). The "About updates" Settings copy
     * documents that auto-update arrives via the in-app banner; this
     * link is the fall-back for users who want to grab an installer
     * manually (first install, or recovery if a future auto-update
     * fails to apply).
     */
    public function openReleasesPage(OpenExternalUrlAction $opener): void
    {
        $opener('https://github.com/nightworksio/beatrax/releases/latest');
    }

    /**
     * Instant-apply toggle for the online exchange-rate fetch opt-in.
     * Mirrors toggleAutoImport: flips the property, then writes via raw
     * query-builder so the DB change is a single round-trip without Eloquent
     * change tracking. Scoped to the current user's row — user_id is never
     * read from the request.
     */
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

    /**
     * Dispatches FetchFxRatesJob for the current user on demand.
     * Sets fxRefreshing to give the UI immediate feedback while the job
     * is queued. The dispatch goes through DispatchFxRatesRefresh (the FX
     * module's Public action) so this Core component never reaches into
     * FX's Internal namespace (cross-module boundary rule).
     * User id is always resolved from CurrentUser — never from the request.
     */
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
     * Custom validation messages. The boundary check for
     * `periodStartDay` returns the same message for any failure of
     * integer / min / max so the user sees one calm sentence
     * regardless of which sub-rule flagged the value.
     *
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
