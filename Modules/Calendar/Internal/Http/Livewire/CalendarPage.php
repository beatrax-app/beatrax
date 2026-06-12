<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * `/calendar` Livewire page — cash-flow calendar surface.
 *
 * Phase 6 Plan 02 wires CalendarQuery into the render method so the
 * page returns real data: entry placement, balance line, paid/missed
 * state, and drill-through links.
 *
 * Security notes (T-06-01, T-06-02):
 *   - month and year are clamped to valid ranges before use.
 *   - visibleAccountIds and balanceAccountIds are passed directly to
 *     CalendarQuery which INTERSECTS them against the authenticated
 *     user's owned accounts before any forUser/forecast call. No
 *     server-side enforcement is needed here beyond the clamp — the
 *     CalendarQuery service is the security boundary.
 */
final class CalendarPage extends Component
{
    /**
     * Display month (1–12). Null = current month.
     */
    #[Url(as: 'month', except: null)]
    public ?int $month = null;

    /**
     * Display year (e.g. 2026). Null = current year.
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

    public function render(ViewFactory $views, CalendarQuery $calendarQuery, CurrentUser $currentUser): View
    {
        $user = $currentUser->user();

        // Resolve display year and month (clamp to valid ranges)
        $now = CarbonImmutable::now();
        $year = ($this->year !== null && $this->year >= 2000 && $this->year <= 2100)
            ? $this->year
            : $now->year;
        $month = ($this->month !== null && $this->month >= 1 && $this->month <= 12)
            ? $this->month
            : $now->month;

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

        $view = $views->make('calendar::livewire.calendar-page', [
            'days' => $days,
            'hasEntries' => $hasEntries,
            'selectedDayDto' => $selectedDayDto,
            'displayYear' => $year,
            'displayMonth' => $month,
        ]);

        /** @phpstan-ignore-next-line method.notFound */
        $view->extends('layouts.app', ['title' => 'Calendar · beatrax']);

        return $view;
    }
}
