<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * `/calendar` Livewire page — cash-flow calendar surface.
 *
 * Phase 6 Plan 01 establishes the bootable shell with #[Url] properties
 * and a static render. CalendarQuery (Plan 02) and the full grid UI
 * (Plan 03) are not yet available — this component returns an empty page
 * shell so the route registers and the browser can visit /calendar.
 *
 * Security notes (T-06-01, T-06-02):
 *   - month and year arrive from the URL as untrusted integers; Plan 03
 *     validates bounds (month 1–12, year within forecast coverage).
 *   - visibleAccountIds and balanceAccountIds arrive from URL/Livewire
 *     payload; Plan 02/03 MUST intersect these against the authenticated
 *     user's owned accounts before passing to ForecastQuery or CalendarQuery.
 *     The type declarations (?int / list<int>) are established here; the
 *     validation logic lands in Plan 02 when CalendarQuery is wired.
 *
 * Service collaborators arrive as parameters on render() and action
 * methods. Constructor injection is banned on Livewire Component subclasses
 * by phpstan-strict-rules.
 */
final class CalendarPage extends Component
{
    /**
     * Display month (1–12). Null = current month.
     * Bounds validation (1–12) added in Plan 03.
     */
    #[Url(as: 'month', except: null)]
    public ?int $month = null;

    /**
     * Display year (e.g. 2026). Null = current year.
     * Bounds validation (within forecast coverage) added in Plan 03.
     */
    #[Url(as: 'year', except: null)]
    public ?int $year = null;

    /**
     * Account IDs whose recurring entries appear on the grid.
     * Null = all accounts (default: entries all ON per D-03).
     * MUST be intersected against user-owned accounts before use (T-06-02).
     *
     * @var list<int>
     */
    public array $visibleAccountIds = [];

    /**
     * Account IDs whose forecast balances are summed for the balance line.
     * Null = spendable default (checking + PayPal ON; savings + ICS OFF per D-03).
     * MUST be intersected against user-owned accounts before use (T-06-02).
     *
     * @var list<int>
     */
    public array $balanceAccountIds = [];

    /**
     * Currently selected day (Y-m-d) — drives the day panel on desktop
     * and the bottom sheet on phone (D-10, D-11).
     */
    public ?string $selectedDay = null;

    public function render(ViewFactory $views): View
    {
        $view = $views->make('calendar::livewire.calendar-page');

        /** @phpstan-ignore-next-line method.notFound */
        $view->extends('layouts.app', ['title' => 'Calendar · beatrax']);

        return $view;
    }
}
