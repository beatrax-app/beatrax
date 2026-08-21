<?php

declare(strict_types=1);

namespace Modules\Shell\Public\Navigation;

use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use Modules\Core\Public\Support\Lang;

// The roster of user-facing destinations, in the order the sidebar lists them.
// The sidebar draws its rows from it and the palette builds its navigation
// entries from it, so a screen reachable from one is reachable from the other.

// Nothing is memoised: label and hint are locale-dependent, and the route
// table is not built until every module has registered.
final class AppNavigation
{
    /**
     * @return list<ResolvedDestination>
     */
    public static function destinations(): array
    {
        $destinations = [];

        foreach (self::rows() as $row) {
            $path = self::pathIfRegistered($row['route'], $row['params']);
            if ($path === null) {
                continue;
            }

            $destinations[] = new ResolvedDestination(
                id: $row['destination'],
                label: Lang::get('core::sidebar.nav.'.$row['key']),
                hint: Lang::get('core::sidebar.hint.'.$row['key']),
                icon: $row['icon'],
                path: $path,
                keywords: $row['keywords'],
            );
        }

        return $destinations;
    }

    public static function url(Destination $destination): string
    {
        $row = self::row($destination);

        return self::urls()->route($row['route'], $row['params']);
    }

    // Root-relative, the way the palette and the sidebar's active-row test
    // both compare against the current request path.
    public static function path(Destination $destination): string
    {
        $row = self::row($destination);

        return self::urls()->route($row['route'], $row['params'], false);
    }

    public static function label(Destination $destination): string
    {
        return Lang::get('core::sidebar.nav.'.self::row($destination)['key']);
    }

    /**
     * @param  array<string, string>  $params
     */
    private static function pathIfRegistered(string $route, array $params): ?string
    {
        $router = Container::getInstance()->make(Router::class);
        if (! $router->getRoutes()->hasNamedRoute($route)) {
            return null;
        }

        return self::urls()->route($route, $params, false);
    }

    private static function urls(): UrlGenerator
    {
        return Container::getInstance()->make(UrlGenerator::class);
    }

    /**
     * @return array{destination: Destination, route: string, params: array<string, string>, icon: string, key: string, keywords: list<string>}
     */
    private static function row(Destination $destination): array
    {
        foreach (self::rows() as $row) {
            if ($row['destination'] === $destination) {
                return $row;
            }
        }

        throw new InvalidArgumentException('No navigation row for destination ['.$destination->value.'].');
    }

    // `key` names one leaf under both core::sidebar.nav and core::sidebar.hint,
    // so a destination's visible label and its palette subtitle are translated
    // as a pair and cannot drift into naming two different screens.
    /**
     * @return list<array{destination: Destination, route: string, params: array<string, string>, icon: string, key: string, keywords: list<string>}>
     */
    private static function rows(): array
    {
        return [
            ...self::overviewRows(),
            ...self::commitmentRows(),
            ...self::planningRows(),
            ...self::insightRows(),
            ...self::ingestionRows(),
            ...self::organiseRows(),
            ...self::settingsRows(),
        ];
    }

    /**
     * @return list<array{destination: Destination, route: string, params: array<string, string>, icon: string, key: string, keywords: list<string>}>
     */
    private static function overviewRows(): array
    {
        return [
            ['destination' => Destination::Dashboard, 'route' => 'dashboard', 'params' => [], 'icon' => '◆', 'key' => 'dashboard', 'keywords' => ['home', 'main', 'this month', 'overview']],
            ['destination' => Destination::Transactions, 'route' => 'transactions.index', 'params' => [], 'icon' => '≡', 'key' => 'transactions', 'keywords' => ['txn', 'ledger', 'payments', 'spending']],
            ['destination' => Destination::Forecasts, 'route' => 'forecast.index', 'params' => [], 'icon' => '↗', 'key' => 'forecasts', 'keywords' => ['scenario', 'predict', 'projection', 'what if']],
            ['destination' => Destination::Calendar, 'route' => 'calendar.index', 'params' => [], 'icon' => '▦', 'key' => 'calendar', 'keywords' => ['bills', 'payments', 'balance', 'cash flow']],
            ['destination' => Destination::Notifications, 'route' => 'notifications.index', 'params' => [], 'icon' => '◈', 'key' => 'notifications', 'keywords' => ['alerts', 'unread', 'inbox', 'messages']],
        ];
    }

    /**
     * @return list<array{destination: Destination, route: string, params: array<string, string>, icon: string, key: string, keywords: list<string>}>
     */
    private static function commitmentRows(): array
    {
        return [
            ['destination' => Destination::Recurring, 'route' => 'recurring.index', 'params' => [], 'icon' => '↻', 'key' => 'recurring', 'keywords' => ['series', 'fixed', 'standing order', 'direct debit']],
            ['destination' => Destination::Subscriptions, 'route' => 'drift.watch', 'params' => [], 'icon' => '↗', 'key' => 'subscriptions', 'keywords' => ['subscription', 'plans', 'memberships', 'price history']],
            ['destination' => Destination::Chains, 'route' => 'chains.index', 'params' => [], 'icon' => '⇉', 'key' => 'chains', 'keywords' => ['routing', 'funding', 'credit card', 'settlement']],
            ['destination' => Destination::UnusualCharges, 'route' => 'drift.index', 'params' => ['type' => 'anomaly'], 'icon' => '◬', 'key' => 'unusual_charges', 'keywords' => ['anomaly', 'spike', 'unexpected', 'outlier']],
            ['destination' => Destination::DriftAlerts, 'route' => 'drift.index', 'params' => [], 'icon' => '⚠', 'key' => 'drift_alerts', 'keywords' => ['drift', 'price rise', 'increase', 'alerts']],
        ];
    }

    /**
     * @return list<array{destination: Destination, route: string, params: array<string, string>, icon: string, key: string, keywords: list<string>}>
     */
    private static function planningRows(): array
    {
        return [
            ['destination' => Destination::Budgets, 'route' => 'budgets.index', 'params' => [], 'icon' => '⊙', 'key' => 'budgets', 'keywords' => ['budget', 'envelope', 'limit', 'spending plan']],
            ['destination' => Destination::Tax, 'route' => 'tax.index', 'params' => [], 'icon' => '⊞', 'key' => 'tax', 'keywords' => ['deduction', 'aangifte', 'export', 'records']],
            ['destination' => Destination::Goals, 'route' => 'goals.index', 'params' => [], 'icon' => '◎', 'key' => 'goals', 'keywords' => ['goal', 'saving', 'target', 'milestone']],
            ['destination' => Destination::Pots, 'route' => 'pots.index', 'params' => [], 'icon' => '◫', 'key' => 'pots', 'keywords' => ['pot', 'jar', 'set aside', 'sinking fund']],
        ];
    }

    /**
     * @return list<array{destination: Destination, route: string, params: array<string, string>, icon: string, key: string, keywords: list<string>}>
     */
    private static function insightRows(): array
    {
        return [
            ['destination' => Destination::Reports, 'route' => 'reports.index', 'params' => [], 'icon' => '▤', 'key' => 'reports', 'keywords' => ['report', 'chart', 'analysis', 'library']],
            ['destination' => Destination::Reconcile, 'route' => 'reconcile.index', 'params' => [], 'icon' => '✓', 'key' => 'reconcile', 'keywords' => ['reconcile', 'statement', 'balance', 'check']],
        ];
    }

    /**
     * @return list<array{destination: Destination, route: string, params: array<string, string>, icon: string, key: string, keywords: list<string>}>
     */
    private static function ingestionRows(): array
    {
        return [
            ['destination' => Destination::Imports, 'route' => 'imports.new', 'params' => [], 'icon' => '⊕', 'key' => 'imports', 'keywords' => ['upload', 'csv', 'mt940', 'camt', 'statement']],
            ['destination' => Destination::CashBook, 'route' => 'cashbook.index', 'params' => [], 'icon' => '€', 'key' => 'cashbook', 'keywords' => ['cash', 'petty cash', 'manual', 'wallet']],
            ['destination' => Destination::Email, 'route' => 'inboxes.index', 'params' => [], 'icon' => '✉', 'key' => 'email', 'keywords' => ['inbox', 'gmail', 'imap', 'mail', 'senders', 'receipts']],
        ];
    }

    /**
     * @return list<array{destination: Destination, route: string, params: array<string, string>, icon: string, key: string, keywords: list<string>}>
     */
    private static function organiseRows(): array
    {
        return [
            ['destination' => Destination::Counterparties, 'route' => 'counterparties.index', 'params' => [], 'icon' => '◉', 'key' => 'counterparties', 'keywords' => ['merchant', 'payee', 'vendor', 'shop']],
            ['destination' => Destination::Triage, 'route' => 'counterparties.triage', 'params' => [], 'icon' => '❋', 'key' => 'triage', 'keywords' => ['unknown', 'queue', 'identify', 'merchant']],
            ['destination' => Destination::Categorization, 'route' => 'uncategorized', 'params' => [], 'icon' => '⌕', 'key' => 'categorization', 'keywords' => ['category', 'rules', 'tag', 'uncategorized']],
            ['destination' => Destination::Community, 'route' => 'community.index', 'params' => [], 'icon' => '◇', 'key' => 'community', 'keywords' => ['shared', 'mystery merchant', 'contribute']],
        ];
    }

    // The sidebar draws Data & Devices as an inline SVG, so this glyph is the
    // palette's alone.
    /**
     * @return list<array{destination: Destination, route: string, params: array<string, string>, icon: string, key: string, keywords: list<string>}>
     */
    private static function settingsRows(): array
    {
        return [
            ['destination' => Destination::DataDevices, 'route' => 'data-devices.index', 'params' => [], 'icon' => '⇄', 'key' => 'data_devices', 'keywords' => ['sync', 'pair', 'device', 'backup', 'phone']],
            ['destination' => Destination::Settings, 'route' => 'settings', 'params' => [], 'icon' => '⚙', 'key' => 'settings', 'keywords' => ['preferences', 'config', 'profile', 'account', 'language']],
        ];
    }
}
