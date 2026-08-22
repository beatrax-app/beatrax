<?php

declare(strict_types=1);

namespace Modules\Core\Public\Navigation;

use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;

/**
 * @link ../../../../.docs/architecture/navigation-destinations.md
 */

// Every place in the app a user can be sent, named once. The sidebar rows, the
// command-palette entries and every cross-screen link spell a destination as
// one of these cases, so no surface can send a reader somewhere the others have
// never heard of.
enum Destination: string
{
    // Each value doubles as the palette's registry id, and mirrors its route
    // name so the per-user Recent cache dedupes on one canonical identifier.
    case Dashboard = 'dashboard';

    case Transactions = 'transactions.index';

    case Forecasts = 'forecast.index';

    case Calendar = 'calendar.index';

    case Notifications = 'notifications.index';

    case Recurring = 'recurring.index';

    case Subscriptions = 'drift.watch';

    case Chains = 'chains.index';

    // Same route as DriftAlerts, filtered to the anomaly section, so the id
    // cannot mirror the route name without colliding with it.
    case UnusualCharges = 'drift.anomaly';

    case DriftAlerts = 'drift.index';

    case Budgets = 'budgets.index';

    case Tax = 'tax.index';

    case Goals = 'goals.index';

    case Pots = 'pots.index';

    case Reports = 'reports.index';

    case Reconcile = 'reconcile.index';

    case Imports = 'imports.new';

    case CashBook = 'cashbook.index';

    case Email = 'inboxes.index';

    case Counterparties = 'counterparties.index';

    case Triage = 'counterparties.triage';

    case Categorization = 'uncategorized';

    case Community = 'community.index';

    case DataDevices = 'data-devices.index';

    case Settings = 'settings';

    public function routeName(): string
    {
        return match ($this) {
            self::UnusualCharges => self::DriftAlerts->value,
            default => $this->value,
        };
    }

    // What makes this case a distinct screen rather than the same one twice —
    // the filter UnusualCharges carries and DriftAlerts does not.
    /**
     * @return array<string, string>
     */
    public function routeParams(): array
    {
        return match ($this) {
            self::UnusualCharges => ['type' => 'anomaly'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function urlFrom(UrlGenerator $urls, array $extra = []): string
    {
        return $urls->route($this->routeName(), array_merge($this->routeParams(), $extra));
    }

    // For Blade, which cannot inject the generator. Code that can injects it
    // and calls urlFrom().
    /**
     * @param  array<string, mixed>  $extra
     */
    public function url(array $extra = []): string
    {
        return $this->urlFrom(self::urls(), $extra);
    }

    // Root-relative, the way the palette and the sidebar's active-row test both
    // compare against the current request path.
    public function path(): string
    {
        return self::urls()->route($this->routeName(), $this->routeParams(), false);
    }

    private static function urls(): UrlGenerator
    {
        return Container::getInstance()->make(UrlGenerator::class);
    }
}
