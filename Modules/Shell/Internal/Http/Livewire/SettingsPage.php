<?php

declare(strict_types=1);

namespace Modules\Shell\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Community\Public\Actions\OpenExternalUrlAction;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\Country;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Enums\Theme;
use Modules\Core\Public\Services\LocaleNegotiator;
use Modules\Core\Public\Services\UserCountry;
use Modules\Core\Public\Support\DriftThresholdOptions;
use Modules\Core\Public\Support\Lang;
use Modules\FX\Public\Actions\DispatchFxRatesRefresh;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\BaseCurrency;

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

    // Validated from rules() against Locale::codes(), not a #[Validate]
    // attribute: an attribute argument must be a constant expression, which
    // would freeze the shipped locales into a second list that drifts the day
    // a language is added.
    public string $locale = LocaleNegotiator::SYSTEM;

    // Empty is a real state, not a missing one: with no country chosen the
    // classification falls back to every region rather than guessing one.
    public string $country = '';

    #[Validate('boolean')]
    public bool $isDeveloper = false;

    public bool $saved = false;

    #[Validate('nullable|string|size:3|exists:currencies,code')]
    public string $baseCurrency = Currency::Eur->value;

    public bool $fxOnlineEnabled = false;

    public bool $fxRefreshing = false;

    public bool $fxRefreshGaveUp = false;

    // What the rate table's newest write looked like when the refresh started.
    // A weekend feed carries the previous business day, so the rate DATE does
    // not move on a successful fetch and cannot be the completion signal.
    public ?string $fxRefreshBaseline = null;

    public int $fxRefreshPolls = 0;

    public ?string $fxLastUpdated = null;

    public function mount(
        CurrentUser $currentUser,
        DatabaseManager $db,
        BaseCurrency $baseCurrency,
        UserCountry $countries,
    ): void {
        $user = $currentUser->user();
        $this->defaultCurrencyView = $user->default_currency_view;
        $this->periodStartDay = $user->period_start_day;
        $this->recurringDetectionWindowMonths = $user->recurring_detection_window_months;
        $this->recurringIncomeMinAmountMinor = $user->recurring_income_min_amount_minor;
        $this->driftAlertThresholdPercent = $user->drift_alert_threshold_percent;
        $this->theme = $user->theme;
        $this->locale = $user->locale ?? LocaleNegotiator::SYSTEM;
        $this->country = $countries->current($user->id);
        $this->isDeveloper = $user->is_developer === true;
        $this->baseCurrency = $user->base_currency ?? $baseCurrency->code();
        $this->fxOnlineEnabled = $user->fx_online_enabled ?? false;

        $this->loadFxLastUpdated($db);
    }

    public function setTheme(string $theme, CurrentUser $currentUser, WriteUserPreference $writeUserPreference): void
    {
        $this->theme = $theme;
        $this->validateOnly('theme');

        ($writeUserPreference)($currentUser->user()->id, ['theme' => $this->theme]);

        // The class this drives lives on the layout's root element, which
        // Livewire never re-renders: without this event the preference was
        // written and the page kept the theme it was served with.
        $this->dispatch('theme-changed', theme: $this->theme);
    }

    // "auto" persists as NULL (no override, so browser detection wins). The
    // locale is retargeted in the same request so the page re-renders at once.
    public function setLocale(
        string $locale,
        CurrentUser $currentUser,
        WriteUserPreference $writeUserPreference,
        LocaleNegotiator $negotiator,
        UrlGenerator $urls,
    ): void {
        $this->locale = $locale;
        $this->validateOnly('locale');

        $storedLocale = $this->locale === LocaleNegotiator::SYSTEM ? null : $this->locale;

        ($writeUserPreference)($currentUser->user()->id, ['locale' => $storedLocale]);

        $negotiator->apply($storedLocale ?? Locale::DEFAULT);

        // The sidebar, top bar and command palette live in the layout, which a
        // component update does not re-render — so they kept the old language
        // until the reader happened to navigate. Re-requesting the page is what
        // makes the switch mean the whole screen.
        $this->redirect($urls->current());
    }

    // Empty is the placeholder, and nothing else in the app can put the
    // preference back to unset. The rule is passed here rather than declared in
    // rules(), which save() runs wholesale: an unchosen country is the empty
    // string, and a required rule up there failed every unrelated field's save.
    public function setCountry(
        string $country,
        CurrentUser $currentUser,
        UserCountry $countries,
        ValidatorFactory $validators,
    ): void {
        if ($country === '') {
            return;
        }

        // Validated before it is assigned. Assigning first left the property
        // holding a value no option carries and the database untouched, so the
        // select rendered with nothing selected at all — not even the country
        // that is still stored.
        $validators->make(
            ['country' => $country],
            ['country' => 'required|in:'.implode(',', array_column(Country::cases(), 'value'))],
        )->validate();

        $this->country = $country;

        $countries->store($currentUser->user()->id, $this->country);
    }

    public function setDevMode(bool $value, CurrentUser $currentUser): void
    {
        $this->isDeveloper = $value;
        $this->validateOnly('isDeveloper');

        $user = $currentUser->user();
        $user->fill(['is_developer' => $value])->save();
    }

    public function openReleasesPage(OpenExternalUrlAction $opener): void
    {
        $opener('https://github.com/beatrax-app/beatrax/releases/latest');
    }

    public function toggleFxOnline(CurrentUser $currentUser, WriteUserPreference $writeUserPreference): void
    {
        $this->fxOnlineEnabled = ! $this->fxOnlineEnabled;

        ($writeUserPreference)($currentUser->user()->id, ['fx_online_enabled' => $this->fxOnlineEnabled]);
    }

    // Bounded because the answer may never come: the fetch runs in a queued
    // job, the providers can all fail, and the button used to say "Refreshing…"
    // for as long as the page stayed open.
    private const FX_REFRESH_MAX_POLLS = 15;

    public function refreshFxRates(DispatchFxRatesRefresh $dispatch, CurrentUser $currentUser, DatabaseManager $db): void
    {
        $this->fxRefreshing = true;
        $this->fxRefreshGaveUp = false;
        $this->fxRefreshPolls = 0;
        $this->fxRefreshBaseline = $this->latestRateWrite($db);

        $dispatch($currentUser->user()->id);
    }

    public function pollFxRefresh(DatabaseManager $db): void
    {
        if (! $this->fxRefreshing) {
            return;
        }

        if ($this->latestRateWrite($db) !== $this->fxRefreshBaseline) {
            $this->fxRefreshing = false;
            $this->loadFxLastUpdated($db);

            return;
        }

        $this->fxRefreshPolls++;

        if ($this->fxRefreshPolls >= self::FX_REFRESH_MAX_POLLS) {
            $this->fxRefreshing = false;
            $this->fxRefreshGaveUp = true;
        }
    }

    // The newest write, not the newest rate date. An upsert always stamps
    // updated_at, so this moves even when the feed repeats a date.
    private function latestRateWrite(DatabaseManager $db): ?string
    {
        $value = $db->connection()->table('exchange_rates')->max('updated_at');

        return is_string($value) ? $value : null;
    }

    private function loadFxLastUpdated(DatabaseManager $db): void
    {
        $latestRate = $db->connection()
            ->table('exchange_rates')
            ->orderByDesc('rate_date')
            ->first(['rate_date']);

        $rawDate = $latestRate->rate_date ?? null;
        $this->fxLastUpdated = is_string($rawDate) ? substr($rawDate, 0, 10) : null;
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

        // No `settings-saved` dispatch: nothing listened for it. Every
        // sibling section on this page owns its own columns, and the only
        // feedback the save owes the user is the $saved line beside it.
        $this->saved = true;
    }

    public function render(
        ViewFactory $views,
        CurrentUser $currentUser,
        DatabaseManager $db,
        BaseCurrency $baseCurrency,
        UserCountry $countries,
    ): View {
        // Sorted by code so the <select> is stable. Both queries use the DB
        // connection directly, never a facade, per BoundaryArchTest.
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

        return $views->make('shell::livewire.settings-page', [
            // The help text needs the per-user directory the user must create,
            // not the shared root inbox-drop folder.
            'userId' => $currentUser->user()->id,
            'forecastingAccounts' => $this->mapAccounts($accounts, $baseCurrency->code()),
            'currencyOptions' => $this->mapCurrencyOptions($currencyRows),
            'countryOptions' => $countries->options(),
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

    // A #[Validate] attribute cannot reference a runtime value, so these
    // allow-lists are composed here from the enum and the options constant.
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'theme' => 'required|in:'.implode(',', Theme::values()),
            'locale' => 'required|in:'.LocaleNegotiator::SYSTEM.','.implode(',', Locale::codes()),
            'driftAlertThresholdPercent' => 'required|integer|in:'.implode(',', DriftThresholdOptions::PERCENTS),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        // Resolved per call, through Lang::get, so a mid-session language switch
        // is reflected in the validation copy.
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
