<?php

declare(strict_types=1);

namespace Modules\Shell\Public\Navigation;

// Every place in the app a user can be sent, named once. The sidebar rows and
// the command-palette entries both spell a destination as one of these cases,
// so neither surface can invent a screen the other has never heard of.
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
}
