<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Calendar\Public\Dto\CalendarDayDto;
use Modules\Core\Models\UserPreference;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use stdClass;

/**
 * @link ../../../../../.docs/features/calendar/architecture.md
 */
final class CalendarPage extends Component
{
    private const int HORIZON_MONTHS = 12;

    use CoercesScalars;

    #[Url(as: 'month', except: null)]
    public ?int $month = null;

    #[Url(as: 'year', except: null)]
    public ?int $year = null;

    /**
     * @var list<int>
     */
    public array $visibleAccountIds = [];

    /**
     * @var list<int>
     */
    public array $balanceAccountIds = [];

    public ?string $selectedDay = null;

    public function mount(CurrentUser $currentUser, CalendarQuery $calendarQuery): void
    {
        $user = $currentUser->user();
        $ownedIds = $calendarQuery->ownedAccountIds($user);

        $pref = UserPreference::query()
            ->where('user_id', $user->id)
            ->first();

        $entriesPref = self::toIntListOrNull($pref?->calendar_entries_accounts);
        $balancePref = self::toIntListOrNull($pref?->calendar_balance_accounts);

        if ($entriesPref !== null) {
            $this->visibleAccountIds = array_values(array_intersect($entriesPref, $ownedIds));
        } elseif ($this->visibleAccountIds === []) {
            $this->visibleAccountIds = $ownedIds;
        }

        if ($balancePref !== null) {
            $this->balanceAccountIds = array_values(array_intersect($balancePref, $ownedIds));
        } elseif ($this->balanceAccountIds === []) {
            $this->balanceAccountIds = $calendarQuery->spendableAccountIds($user);
        }
    }

    public function prevMonth(Clock $clock): void
    {
        $display = $this->resolveDisplay($clock);
        $prev = CarbonImmutable::parse(sprintf('%04d-%02d-01', $display['year'], $display['month']))->subMonth();
        $this->year = $prev->year;
        $this->month = $prev->month;
        $this->selectedDay = null;
    }

    public function nextMonth(Clock $clock): void
    {
        $display = $this->resolveDisplay($clock);
        $next = CarbonImmutable::parse(sprintf('%04d-%02d-01', $display['year'], $display['month']))->addMonth();

        if ($this->exceedsCeiling($next->year, $next->month, $clock)) {
            return;
        }

        $this->year = $next->year;
        $this->month = $next->month;
        $this->selectedDay = null;
    }

    public function selectDay(string $date, Clock $clock): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }

        // The shape regex alone admits impossible dates that would make
        // CarbonImmutable::parse() throw — validate the calendar date
        // strictly before parsing.
        [$y, $m, $d] = array_map(intval(...), explode('-', $date));
        if (! checkdate($m, $d, $y)) {
            return;
        }

        $display = $this->resolveDisplay($clock);
        $parsed = CarbonImmutable::parse($date);

        if ($parsed->year !== $display['year'] || $parsed->month !== $display['month']) {
            return;
        }

        $this->selectedDay = $date;

        // On phone width the bottom sheet handles its own show; the JS
        // event triggers the <x-bottom-sheet name="day-detail"> open state.
        $this->dispatch('open-sheet', name: 'day-detail');
    }

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

    // Independent from the entries toggle above — the two controls never
    // affect each other.
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

    public function persistAccountPrefs(CurrentUser $currentUser, DatabaseManager $db): void
    {
        $ownedIds = $this->fetchOwnedAccountIds($db, $currentUser->id());

        // Public Livewire array properties are client-controlled — filter to
        // ints and intersect against owned accounts before persisting, so
        // the DB never stores attacker-shaped payloads or foreign ids.
        $this->visibleAccountIds = self::sanitizeAccountIds($this->visibleAccountIds, $ownedIds);
        $this->balanceAccountIds = self::sanitizeAccountIds($this->balanceAccountIds, $ownedIds);

        UserPreference::query()->updateOrCreate(
            ['user_id' => $currentUser->id()],
            [
                'calendar_entries_accounts' => $this->visibleAccountIds,
                'calendar_balance_accounts' => $this->balanceAccountIds,
            ],
        );
    }

    public function render(
        ViewFactory $views,
        CalendarQuery $calendarQuery,
        CurrentUser $currentUser,
        DatabaseManager $db,
        Clock $clock,
    ): View {
        $user = $currentUser->user();
        $display = $this->resolveDisplay($clock);
        $year = $display['year'];
        $month = $display['month'];

        $accounts = $db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'kind']);

        $accountRoster = self::buildAccountRoster($accounts);

        // A user with zero accounts has nothing to filter on — pass null
        // ("no filter") so series not yet linked to any account still
        // render, since an explicit [] would otherwise mean deselect-all.
        $days = $calendarQuery->forMonth(
            $user,
            $year,
            $month,
            $accountRoster === [] ? null : $this->visibleAccountIds,
            $accountRoster === [] ? null : $this->balanceAccountIds,
        );

        $ceiling = $clock->now()->addMonths(self::HORIZON_MONTHS);
        $atCeiling = ($year > $ceiling->year)
            || ($year === $ceiling->year && $month >= $ceiling->month);

        $view = $views->make('calendar::livewire.calendar-page', [
            'days' => $days,
            'hasEntries' => self::daysHaveEntries($days),
            'selectedDayDto' => $this->findSelectedDay($days),
            'displayYear' => $year,
            'displayMonth' => $month,
            'isComputingAny' => self::daysAreComputing($days),
            'accountRoster' => $accountRoster,
            'atCeiling' => $atCeiling,
        ]);

        /** @phpstan-ignore-next-line method.notFound */
        $view->extends('layouts.app', ['title' => Lang::get('calendar::messages.page.title').' · Beatrax']);

        return $view;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param  Collection<int, stdClass>  $accounts
     * @return list<array{id: int, name: string, kind: string}>
     */
    private static function buildAccountRoster(Collection $accounts): array
    {
        $roster = [];
        foreach ($accounts as $account) {
            $roster[] = [
                'id' => self::toInt($account->id),
                'name' => self::toString($account->name ?? null),
                'kind' => self::toString($account->kind ?? null),
            ];
        }

        return $roster;
    }

    /**
     * @param  list<CalendarDayDto>  $days
     */
    private static function daysHaveEntries(array $days): bool
    {
        foreach ($days as $day) {
            if ($day->entries !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<CalendarDayDto>  $days
     */
    private static function daysAreComputing(array $days): bool
    {
        foreach ($days as $day) {
            if ($day->isComputing) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<CalendarDayDto>  $days
     */
    private function findSelectedDay(array $days): ?CalendarDayDto
    {
        if ($this->selectedDay === null) {
            return null;
        }

        foreach ($days as $day) {
            if ($day->date->toDateString() === $this->selectedDay) {
                return $day;
            }
        }

        return null;
    }

    /**
     * @return array{year: int, month: int}
     */
    private function resolveDisplay(Clock $clock): array
    {
        $now = $clock->now();
        $year = ($this->year !== null && $this->year >= 2000 && $this->year <= 2100)
            ? $this->year
            : $now->year;
        $month = ($this->month !== null && $this->month >= 1 && $this->month <= 12)
            ? $this->month
            : $now->month;

        // Also clamps to the 12-month forward ceiling so a tampered
        // ?year=&month= URL cannot render a month beyond the forecast
        // horizon — the same invariant nextMonth() enforces.
        $ceiling = $now->addMonths(self::HORIZON_MONTHS);
        if ($year > $ceiling->year || ($year === $ceiling->year && $month > $ceiling->month)) {
            $year = $ceiling->year;
            $month = $ceiling->month;
        }

        return ['year' => $year, 'month' => $month];
    }

    private function exceedsCeiling(int $year, int $month, Clock $clock): bool
    {
        $ceiling = $clock->now()->addMonths(self::HORIZON_MONTHS);

        return ($year > $ceiling->year)
            || ($year === $ceiling->year && $month > $ceiling->month);
    }

    /**
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

    // Typed array<array-key, mixed> deliberately: public Livewire array
    // properties are client-controlled and their docblock list<int> shape
    // cannot be trusted at this boundary.
    /**
     * @param  array<array-key, mixed>  $ids
     * @param  list<int>  $ownedIds
     * @return list<int>
     */
    private static function sanitizeAccountIds(array $ids, array $ownedIds): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_int($id) && in_array($id, $ownedIds, true) && ! in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }

        return $clean;
    }

    // Preserves the null-vs-array distinction: null means "never
    // configured"; an array — even empty — is an explicit user choice.
    /**
     * @return list<int>|null
     */
    private static function toIntListOrNull(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $ints = [];
        foreach ($value as $v) {
            if (is_int($v)) {
                $ints[] = $v;
            }
        }

        return $ints;
    }
}
