<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;

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
     * on each sweep. The lower bound of 3 months keeps monthly
     * detection statistically meaningful (at least three observations
     * for a stable cadence); the upper bound of 60 months caps the
     * sweep cost so very long histories still finish in reasonable
     * time.
     */
    #[Validate('required|integer|min:3|max:60')]
    public int $recurringDetectionWindowMonths = 18;

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
     * Inline "Saved." confirmation flag flipped by save() and consumed by
     * the Blade view via `@if ($saved)` + `wire:transition.duration.4000ms`
     * so the confirmation auto-dismisses after four seconds.
     */
    public bool $saved = false;

    public function mount(CurrentUser $currentUser): void
    {
        $user = $currentUser->user();
        $this->defaultCurrencyView = $user->default_currency_view;
        $this->periodStartDay = $user->period_start_day;
        $this->autoImportFromDropFolder = (bool) $user->auto_import_drop_folder;
        $this->recurringDetectionWindowMonths = $user->recurring_detection_window_months;
        $this->recurringIncomeMinAmountMinor = $user->recurring_income_min_amount_minor;
        $this->driftAlertThresholdPercent = $user->drift_alert_threshold_percent;
        $this->theme = $user->theme;
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

    public function save(CurrentUser $currentUser): void
    {
        $this->validate();

        $user = $currentUser->user();
        $user->default_currency_view = $this->defaultCurrencyView;
        $user->period_start_day = $this->periodStartDay;
        $user->recurring_detection_window_months = $this->recurringDetectionWindowMonths;
        $user->recurring_income_min_amount_minor = $this->recurringIncomeMinAmountMinor;
        $user->drift_alert_threshold_percent = $this->driftAlertThresholdPercent;
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

        return $views->make('core::livewire.settings-page', [
            // Expose the per-user inbox-drop path so the help text
            // renders the directory the user must actually create
            // (storage/app/inbox-drop/{userId}/) rather than the
            // root inbox-drop folder.
            'userId' => $currentUser->user()->id,
            'forecastingAccounts' => $accountList,
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
            'recurringDetectionWindowMonths.required' => 'Choose between 3 and 60 months.',
            'recurringDetectionWindowMonths.integer' => 'Choose between 3 and 60 months.',
            'recurringDetectionWindowMonths.min' => 'Choose between 3 and 60 months.',
            'recurringDetectionWindowMonths.max' => 'Choose between 3 and 60 months.',
            'recurringIncomeMinAmountMinor.required' => 'Enter an amount from €0 upward.',
            'recurringIncomeMinAmountMinor.integer' => 'Enter an amount from €0 upward.',
            'recurringIncomeMinAmountMinor.min' => 'Enter an amount from €0 upward.',
            'recurringIncomeMinAmountMinor.max' => 'Enter an amount from €0 upward.',
            'driftAlertThresholdPercent.required' => 'Choose a threshold from 1%, 2%, 5%, 10%, 25%, or 50%.',
            'driftAlertThresholdPercent.integer' => 'Choose a threshold from 1%, 2%, 5%, 10%, 25%, or 50%.',
            'driftAlertThresholdPercent.in' => 'Choose a threshold from 1%, 2%, 5%, 10%, 25%, or 50%.',
        ];
    }
}
