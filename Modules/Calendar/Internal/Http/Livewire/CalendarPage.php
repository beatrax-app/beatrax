<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Core\Models\UserPreference;
use Modules\Core\Public\Contracts\CurrentUser;
use stdClass;

/**
 * `/calendar` Livewire page — cash-flow calendar surface.
 *
 * Phase 6 Plan 03 adds the full UI contract: month navigation with
 * 12-month forward horizon ceiling (D-14), the Accounts popover with
 * two independent per-account controls (D-02, D-03) that persist to
 * user_preferences, and the day selection that drives the right-rail
 * day panel or the phone bottom sheet (D-10, D-11).
 *
 * Security notes (T-06-01, T-06-02):
 *   - month and year are clamped to valid ranges before use.
 *   - visibleAccountIds and balanceAccountIds are INTERSECTED against
 *     the authenticated user's owned accounts before any CalendarQuery
 *     call. Foreign IDs are silently dropped.
 *   - The 12-month forward ceiling is enforced both on nextMonth() and
 *     at render time so a tampered ?year=&month= cannot exceed the
 *     forecast horizon.
 */
final class CalendarPage extends Component
{
    /**
     * Display month (1–12). Null = current month (from clock).
     */
    #[Url(as: 'month', except: null)]
    public ?int $month = null;

    /**
     * Display year (e.g. 2026). Null = current year (from clock).
     */
    #[Url(as: 'year', except: null)]
    public ?int $year = null;

    /**
     * Account IDs whose recurring entries appear on the grid.
     * Empty = all accounts (default: entries all ON per D-02).
     * Intersected against user-owned accounts in CalendarQuery (T-06-02).
     *
     * @var list<int>
     */
    public array $visibleAccountIds = [];

    /**
     * Account IDs whose forecast balances are summed for the balance line.
     * Empty = spendable default (checking + PayPal ON; ICS OFF per D-03).
     * Intersected against user-owned accounts in CalendarQuery (T-06-02).
     *
     * @var list<int>
     */
    public array $balanceAccountIds = [];

    /**
     * Currently selected day (Y-m-d) — drives the day panel on desktop
     * and the bottom sheet on phone (D-10, D-11).
     */
    public ?string $selectedDay = null;

    /**
     * Load persisted account preferences from user_preferences on first
     * page load. Empty arrays stay as-is (CalendarQuery resolves defaults).
     */
    public function mount(CurrentUser $currentUser, DatabaseManager $db): void
    {
        $userId = $currentUser->id();

        $row = $db->connection()->table('user_preferences')
            ->where('user_id', $userId)
            ->first(['calendar_entries_accounts', 'calendar_balance_accounts']);

        if ($row instanceof stdClass) {
            $entriesRaw = $row->calendar_entries_accounts;
            if (is_string($entriesRaw) && $entriesRaw !== '') {
                $decoded = json_decode($entriesRaw, true);
                if (is_array($decoded)) {
                    $ints = [];
                    foreach ($decoded as $v) {
                        if (is_int($v)) {
                            $ints[] = $v;
                        }
                    }
                    $this->visibleAccountIds = $ints;
                }
            }

            $balanceRaw = $row->calendar_balance_accounts;
            if (is_string($balanceRaw) && $balanceRaw !== '') {
                $decoded = json_decode($balanceRaw, true);
                if (is_array($decoded)) {
                    $bInts = [];
                    foreach ($decoded as $v) {
                        if (is_int($v)) {
                            $bInts[] = $v;
                        }
                    }
                    $this->balanceAccountIds = $bInts;
                }
            }
        }
    }

    /**
     * Navigate to the previous month (always allowed — no lower bound).
     */
    public function prevMonth(): void
    {
        $display = $this->resolveDisplay();
        $prev = CarbonImmutable::parse(sprintf('%04d-%02d-01', $display['year'], $display['month']))->subMonth();
        $this->year = $prev->year;
        $this->month = $prev->month;
        $this->selectedDay = null;
    }

    /**
     * Navigate to the next month. No-op when the next month would exceed
     * the 12-month forward forecast horizon (T-06-01, D-14).
     */
    public function nextMonth(): void
    {
        $display = $this->resolveDisplay();
        $next = CarbonImmutable::parse(sprintf('%04d-%02d-01', $display['year'], $display['month']))->addMonth();

        if ($this->exceedsCeiling($next->year, $next->month)) {
            return;
        }

        $this->year = $next->year;
        $this->month = $next->month;
        $this->selectedDay = null;
    }

    /**
     * Select a day and open the day panel (desktop) or bottom sheet (phone).
     * Validates that the date is a Y-m-d string within the display month.
     */
    public function selectDay(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }

        $display = $this->resolveDisplay();
        $parsed = CarbonImmutable::parse($date);

        if ($parsed->year !== $display['year'] || $parsed->month !== $display['month']) {
            return;
        }

        $this->selectedDay = $date;

        // On phone width the bottom sheet handles its own show; the JS
        // event triggers the <x-bottom-sheet name="day-detail"> open state.
        $this->dispatch('open-sheet', name: 'day-detail');
    }

    /**
     * Toggle an account's inclusion in the visible-entries set (D-02).
     * Validates ownership against the user's account roster.
     * After toggling, call persistAccountPrefs() to save.
     */
    public function toggleEntriesAccount(int $accountId, DatabaseManager $db, CurrentUser $currentUser): void
    {
        $ownedIds = $this->fetchOwnedAccountIds($db, $currentUser->id());

        if (! in_array($accountId, $ownedIds, true)) {
            return;
        }

        $current = $this->visibleAccountIds;
        if (in_array($accountId, $current, true)) {
            $this->visibleAccountIds = array_values(array_filter($current, fn (int $id): bool => $id !== $accountId));
        } else {
            $this->visibleAccountIds = [...$current, $accountId];
        }
    }

    /**
     * Toggle an account's inclusion in the balance-calculation set (D-02).
     * Independent from the entries toggle — the two controls do NOT affect
     * each other.
     */
    public function toggleBalanceAccount(int $accountId, DatabaseManager $db, CurrentUser $currentUser): void
    {
        $ownedIds = $this->fetchOwnedAccountIds($db, $currentUser->id());

        if (! in_array($accountId, $ownedIds, true)) {
            return;
        }

        $current = $this->balanceAccountIds;
        if (in_array($accountId, $current, true)) {
            $this->balanceAccountIds = array_values(array_filter($current, fn (int $id): bool => $id !== $accountId));
        } else {
            $this->balanceAccountIds = [...$current, $accountId];
        }
    }

    /**
     * Persist the current account preference arrays to user_preferences.
     * Called on popover close so the UI round-trip stays one network call
     * rather than one per toggle.
     */
    public function persistAccountPrefs(CurrentUser $currentUser): void
    {
        UserPreference::query()->updateOrCreate(
            ['user_id' => $currentUser->id()],
            [
                'calendar_entries_accounts' => $this->visibleAccountIds === []
                    ? null
                    : $this->visibleAccountIds,
                'calendar_balance_accounts' => $this->balanceAccountIds === []
                    ? null
                    : $this->balanceAccountIds,
            ],
        );
    }

    public function render(
        ViewFactory $views,
        CalendarQuery $calendarQuery,
        CurrentUser $currentUser,
        DatabaseManager $db,
    ): View {
        $user = $currentUser->user();
        $display = $this->resolveDisplay();
        $year = $display['year'];
        $month = $display['month'];

        // CalendarQuery handles security (T-06-02): intersects account IDs against
        // user-owned accounts; foreign IDs are silently dropped.
        $days = $calendarQuery->forMonth(
            $user,
            $year,
            $month,
            $this->visibleAccountIds,
            $this->balanceAccountIds,
        );

        // Determine empty state: no entries across any day in the grid
        $hasEntries = false;
        foreach ($days as $day) {
            if ($day->entries !== []) {
                $hasEntries = true;
                break;
            }
        }

        // Resolve the day DTO for the selected day (for the day panel)
        $selectedDayDto = null;
        if ($this->selectedDay !== null) {
            foreach ($days as $day) {
                if ($day->date->toDateString() === $this->selectedDay) {
                    $selectedDayDto = $day;
                    break;
                }
            }
        }

        // Any day where isComputing is true means balance data is still being assembled.
        $isComputingAny = false;
        foreach ($days as $day) {
            if ($day->isComputing) {
                $isComputingAny = true;
                break;
            }
        }

        // Build the account roster for the Accounts popover.
        $accounts = $db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'kind']);

        $accountRoster = [];
        foreach ($accounts as $account) {
            /** @var stdClass $account */
            $accountId = self::toInt($account->id);
            $accountRoster[] = [
                'id' => $accountId,
                'name' => self::toString($account->name ?? null),
                'kind' => self::toString($account->kind ?? null),
            ];
        }

        // Ceiling month: today + 12 months (D-14)
        $ceiling = CarbonImmutable::now()->addMonths(12);
        $atCeiling = ($year > $ceiling->year)
            || ($year === $ceiling->year && $month >= $ceiling->month);

        $view = $views->make('calendar::livewire.calendar-page', [
            'days' => $days,
            'hasEntries' => $hasEntries,
            'selectedDayDto' => $selectedDayDto,
            'displayYear' => $year,
            'displayMonth' => $month,
            'isComputingAny' => $isComputingAny,
            'accountRoster' => $accountRoster,
            'atCeiling' => $atCeiling,
        ]);

        /** @phpstan-ignore-next-line method.notFound */
        $view->extends('layouts.app', ['title' => 'Calendar · beatrax']);

        return $view;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the effective display year + month from the #[Url] props,
     * clamping to valid ranges. Called by both render() and action methods
     * so the clamping logic lives in one place.
     *
     * @return array{year: int, month: int}
     */
    private function resolveDisplay(): array
    {
        $now = CarbonImmutable::now();
        $year = ($this->year !== null && $this->year >= 2000 && $this->year <= 2100)
            ? $this->year
            : $now->year;
        $month = ($this->month !== null && $this->month >= 1 && $this->month <= 12)
            ? $this->month
            : $now->month;

        return ['year' => $year, 'month' => $month];
    }

    /**
     * True when the given year+month is at or beyond the 12-month forward
     * forecast ceiling (D-14).
     */
    private function exceedsCeiling(int $year, int $month): bool
    {
        $ceiling = CarbonImmutable::now()->addMonths(12);

        return ($year > $ceiling->year)
            || ($year === $ceiling->year && $month > $ceiling->month);
    }

    /**
     * Fetch the list of account IDs owned by this user (for ownership validation).
     *
     * @return list<int>
     */
    private function fetchOwnedAccountIds(DatabaseManager $db, int $userId): array
    {
        $rows = $db->connection()->table('accounts')
            ->where('user_id', $userId)
            ->pluck('id')
            ->toArray();

        $ids = [];
        foreach ($rows as $v) {
            if (is_int($v)) {
                $ids[] = $v;
            } elseif (is_string($v) && ctype_digit($v)) {
                $ids[] = (int) $v;
            }
        }

        return $ids;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
