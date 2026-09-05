<?php

declare(strict_types=1);

namespace Modules\Shell\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Budgets\Public\Services\EnvelopePeriodRekeyer;
use Modules\Community\Public\Actions\OpenExternalUrlAction;
use Modules\Core\Models\User;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\Country;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Enums\Theme;
use Modules\Core\Public\Exceptions\IdReadBackFailedException;
use Modules\Core\Public\Services\LocaleNegotiator;
use Modules\Core\Public\Services\UserCountry;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\DriftThresholdOptions;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\ProjectLinks;
use Modules\FX\Public\Actions\DispatchFxRatesRefresh;
use Modules\FX\Public\Services\FxRefreshStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\CurrencyView;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Support\CurrencyDisplayName;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Support\RecurringDetectionWindow;

final class SettingsPage extends Component
{
    // Validated from rules() for the same reason locale is: an attribute
    // argument must be a constant expression, and a hand-written copy of the
    // enum's values drifts from it the day a third view is added.
    public string $defaultCurrencyView = CurrencyView::BaseOnly->value;

    // In rules() beside the enum-derived ones: PeriodQuery clamps to the same
    // pair, so a validator that widened alone would clamp instead of refuse.
    public int $periodStartDay = PeriodQuery::MIN_START_DAY;

    public bool $confirmingPeriodMove = false;

    // The floor is the detectors' own fallback: below two months a monthly
    // series cannot show the two occurrences they need, so a validator that
    // widened alone would let the reader pick a window that detects nothing.
    #[Validate('required|integer|min:'.RecurringDetectionWindow::MINIMUM_MONTHS.'|max:'.RecurringDetectionWindow::MAXIMUM_MONTHS)]
    public int $recurringDetectionWindowMonths = RecurringDetectionWindow::MINIMUM_MONTHS;

    #[Validate('required|integer|min:0|max:100000000')]
    public int $recurringIncomeMinAmountMinor = User::DEFAULT_RECURRING_INCOME_MIN_AMOUNT_MINOR;

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

    // The desktop's electron-updater chain is inert on a device: all three
    // AutoUpdater listeners return early on a mobile runtime, so the About
    // section names the store that does the updating instead.
    #[Locked]
    public bool $onPhone = false;

    public function mount(
        CurrentUser $currentUser,
        DatabaseManager $db,
        BaseCurrency $baseCurrency,
        UserCountry $countries,
    ): void {
        $user = $currentUser->user();
        $this->defaultCurrencyView = $user->default_currency_view->value;
        $this->periodStartDay = $user->period_start_day;
        $this->recurringDetectionWindowMonths = $user->recurring_detection_window_months;
        $this->recurringIncomeMinAmountMinor = $user->recurring_income_min_amount_minor;
        $this->driftAlertThresholdPercent = $user->drift_alert_threshold_percent;
        $this->theme = $user->theme;
        $this->locale = $user->locale ?? LocaleNegotiator::SYSTEM;
        $this->country = $countries->current($user->id);
        $this->isDeveloper = $user->is_developer === true;
        $this->baseCurrency = $baseCurrency->forUser($user);
        $this->fxOnlineEnabled = $user->fx_online_enabled ?? false;
        $this->onPhone = UserDataPathService::platform() !== null;

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

        // Named route, not current(): this runs inside the POST to Livewire's
        // update endpoint, so "the current URL" is that endpoint, and the
        // browser followed that redirect with a GET and got 405.
        $this->redirect($urls->route('settings'));
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
        $opener(ProjectLinks::LATEST_RELEASE_URL);
    }

    public function toggleFxOnline(CurrentUser $currentUser, WriteUserPreference $writeUserPreference): void
    {
        $this->fxOnlineEnabled = ! $this->fxOnlineEnabled;

        ($writeUserPreference)($currentUser->user()->id, ['fx_online_enabled' => $this->fxOnlineEnabled]);
    }

    // Bounded because the answer may never come: the fetch runs in a queued
    // job, the providers can all fail, and the button used to say "Refreshing…"
    // for as long as the page stayed open.
    private const int FX_REFRESH_MAX_POLLS = 15;

    public function refreshFxRates(DispatchFxRatesRefresh $dispatch, CurrentUser $currentUser, DatabaseManager $db, FxRefreshStatus $fxStatus): void
    {
        $this->fxRefreshing = true;
        $this->fxRefreshGaveUp = false;
        $this->fxRefreshPolls = 0;
        $this->fxRefreshBaseline = $this->latestRateWrite($db);

        $fxStatus->clear($currentUser->user()->id);
        $dispatch($currentUser->user()->id);
    }

    public function pollFxRefresh(DatabaseManager $db, CurrentUser $currentUser, FxRefreshStatus $fxStatus): void
    {
        if (! $this->fxRefreshing) {
            return;
        }

        // The job records why it gave up. Without reading that, the only signal
        // was fifteen polls of silence, so the reader was told the refresh had
        // stopped and never why.
        if ($fxStatus->lastFailure($currentUser->user()->id) !== null) {
            $this->fxRefreshing = false;
            $this->fxRefreshGaveUp = true;

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

    public function save(CurrentUser $currentUser, EnvelopePeriodRekeyer $envelopePeriods, WriteUserPreference $preferences): void
    {
        $this->validate();

        $user = $currentUser->user();
        $previousStartDay = $user->period_start_day;
        $periodStartDayMoved = $previousStartDay !== $this->periodStartDay;

        // The rekey at the foot of this method deletes every envelope
        // assignment and re-files it, summing the two amounts wherever a pair
        // of old periods folds onto one new one. Setting the day back re-runs
        // the same merge rather than undoing it, so the move is asked for.
        if ($periodStartDayMoved && ! $this->confirmingPeriodMove) {
            $this->confirmingPeriodMove = true;

            return;
        }

        $this->confirmingPeriodMove = false;
        $user->default_currency_view = CurrencyView::from($this->defaultCurrencyView);
        $user->period_start_day = $this->periodStartDay;
        $user->recurring_detection_window_months = $this->recurringDetectionWindowMonths;
        $user->recurring_income_min_amount_minor = $this->recurringIncomeMinAmountMinor;
        $user->drift_alert_threshold_percent = $this->driftAlertThresholdPercent;
        $user->base_currency = $this->baseCurrency;

        /** @var list<string> $changed */
        $changed = array_keys($user->getDirty());
        $user->save();

        // Six settings the other device reads too, and every one of them keys
        // something that DOES sync: move the period day here and the re-keyed
        // envelope rows arrive on a phone still asking for last month's window.
        $preferences->announce($user->id, $changed);

        // Envelope rows are keyed by a literal period-start date, so moving
        // the day strands every one of them outside the periods the carryover
        // fold walks. Re-keyed after the save, and handed the day it replaced,
        // which is what says which period each stored key was written for.
        if ($periodStartDayMoved) {
            try {
                $envelopePeriods->rekeyToCurrentPeriods($previousStartDay);
            } catch (IdReadBackFailedException) {
                // The rekey owns one transaction, so its refusal left every
                // envelope row on the old key. Only the old day matches those,
                // and a day saved without them reads as a plan of nothing.
                $this->periodStartDay = $previousStartDay;
                $user->period_start_day = $previousStartDay;
                $user->save();
                $preferences->announce($user->id, ['period_start_day']);
                $this->addError('periodStartDay', Lang::get('core::settings.errors.period_move_failed'));

                return;
            }
        }

        // No `settings-saved` dispatch: nothing listened for it. Every
        // sibling section on this page owns its own columns, and the only
        // feedback the save owes the user is the $saved line beside it.
        $this->saved = true;
    }

    public function cancelPeriodMove(CurrentUser $currentUser): void
    {
        $this->periodStartDay = $currentUser->user()->period_start_day;
        $this->confirmingPeriodMove = false;
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
            'accounts' => $this->mapAccounts($accounts, $baseCurrency->code()),
            'currencyOptions' => $this->mapCurrencyOptions($currencyRows),
            'countryOptions' => $countries->options(),
            'exampleCurrency' => $this->exampleCurrency(),
        ]);
    }

    // baseCurrency is a form field on this page, so between an edit and a save
    // it holds whatever was typed — including a code brick/money refuses. Both
    // the worked example in the view and the bound in the validation copy were
    // formatted in it, and threw over the very input they were describing.
    private function exampleCurrency(): string
    {
        return Money::tryOfMinor(0, $this->baseCurrency) instanceof Money
            ? $this->baseCurrency
            : Currency::Eur->value;
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
            $name = is_string($row->name ?? null) ? $row->name : null;
            if ($code !== '') {
                $currencyOptions[$code] = CurrencyDisplayName::forCode($code, $name);
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
            'defaultCurrencyView' => 'required|in:'.implode(',', CurrencyView::values()),
            'periodStartDay' => 'required|integer|min:'.PeriodQuery::MIN_START_DAY.'|max:'.PeriodQuery::MAX_START_DAY,
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
        $amount = Lang::get('core::settings.errors.amount', ['zero' => Money::ofMinor(0, $this->exampleCurrency())->formatWholeUnits()]);
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
