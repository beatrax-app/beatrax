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
use Modules\Calendar\Internal\Dto\CalendarDayDto;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Core\Models\UserPreference;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserPreferenceWriter;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Services\BaseCurrency;
use stdClass;

final class CalendarPage extends Component
{
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

        // The shape regex admits impossible dates like 2026-02-31, which would
        // make CarbonImmutable::parse() throw.
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

    public function persistAccountPrefs(CurrentUser $currentUser, DatabaseManager $db, UserPreferenceWriter $preferences): void
    {
        $ownedIds = $this->fetchOwnedAccountIds($db, $currentUser->id());

        $this->visibleAccountIds = self::sanitizeAccountIds($this->visibleAccountIds, $ownedIds);
        $this->balanceAccountIds = self::sanitizeAccountIds($this->balanceAccountIds, $ownedIds);

        $preferences->write($currentUser->id(), [
            'calendar_entries_accounts' => $this->visibleAccountIds,
            'calendar_balance_accounts' => $this->balanceAccountIds,
        ]);
    }

    public function render(
        ViewFactory $views,
        CalendarQuery $calendarQuery,
        CurrentUser $currentUser,
        DatabaseManager $db,
        Clock $clock,
        BaseCurrency $baseCurrency,
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

        // With zero accounts there is nothing to filter on, and [] would read
        // as deselect-all — null is the only value that still shows unlinked
        // series.
        $balanceFilter = $accountRoster === [] ? null : $this->balanceAccountIds;

        $days = $calendarQuery->forMonth(
            $user,
            $year,
            $month,
            $accountRoster === [] ? null : $this->visibleAccountIds,
            $balanceFilter,
        );

        // Days with no balance source read as "computing" so the corner shows
        // "—" instead of a fabricated €0. Only a real source makes that a
        // pending projection rather than a permanently absent one.
        $balanceSources = $calendarQuery->effectiveBalanceAccountIds(
            $balanceFilter,
            array_column($accountRoster, 'id'),
            $user,
        );

        $ceiling = $clock->now()->addMonths(CalendarQuery::HORIZON_MONTHS);
        $atCeiling = ($year > $ceiling->year)
            || ($year === $ceiling->year && $month >= $ceiling->month);

        $view = $views->make('calendar::livewire.calendar-page', [
            'days' => $days,
            'hasProjectableEntries' => $calendarQuery->hasProjectableEntries($user),
            'selectedDayDto' => $this->findSelectedDay($days),
            'displayYear' => $year,
            'displayMonth' => $month,
            'isComputingAny' => $balanceSources !== [] && self::daysAreComputing($days),
            'accountRoster' => $accountRoster,
            'atCeiling' => $atCeiling,
            'baseCurrency' => $baseCurrency->forUser($user),
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('calendar::messages.page.title').' · Beatrax']);

        return $view;
    }

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

        // Clamps to the same ceiling nextMonth() enforces, so a tampered
        // ?year=&month= cannot render past the forecast horizon.
        $ceiling = $now->addMonths(CalendarQuery::HORIZON_MONTHS);
        if ($year > $ceiling->year || ($year === $ceiling->year && $month > $ceiling->month)) {
            $year = $ceiling->year;
            $month = $ceiling->month;
        }

        return ['year' => $year, 'month' => $month];
    }

    private function exceedsCeiling(int $year, int $month, Clock $clock): bool
    {
        $ceiling = $clock->now()->addMonths(CalendarQuery::HORIZON_MONTHS);

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

    // array<array-key, mixed> deliberately: a public Livewire array property is
    // client-controlled, so its declared list<int> shape is not trustworthy.
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
