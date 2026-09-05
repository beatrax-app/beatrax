<?php

declare(strict_types=1);

namespace Modules\Shell\Public\Navigation;

use Illuminate\Container\Container;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Lang;

// The roster of user-facing destinations, in the order the sidebar lists them.
// The sidebar draws its rows from it and the palette builds its navigation
// entries from it, so a screen reachable from one is reachable from the other.

// Core names the destinations and how each is addressed; what a row adds here
// is chrome — order, icon, label, and the words that find it in the palette.

// Nothing is memoised: label and hint are locale-dependent, and the route
// table is not built until every module has registered.

// Two of the icons below end in an invisible U+FE0F. Without it the two phone
// engines disagree about whether the character is a picture or a glyph, and
// deleting one because the editor shows nothing there brings the disagreement
// back.
/**
 * @link ../../../../.docs/conventions/emoji-presentation-selector.md
 */
final class AppNavigation
{
    /**
     * @return list<ResolvedDestination>
     */
    public static function destinations(): array
    {
        $destinations = [];

        foreach (self::rows() as $row) {
            if (! self::isRegistered($row['destination'])) {
                continue;
            }

            $destinations[] = new ResolvedDestination(
                id: $row['destination'],
                label: Lang::get('core::sidebar.nav.'.$row['key']),
                hint: Lang::get('core::sidebar.hint.'.$row['key']),
                icon: $row['icon'],
                path: $row['destination']->path(),
                keywords: $row['keywords'],
            );
        }

        return $destinations;
    }

    // The drawer spelled every glyph out again beside its own <a>, so the one
    // in the row list and the one on screen could disagree — and did: Forecasts
    // and Subscriptions both wore ↗ until the drawer was read on a phone.
    public static function icon(Destination $destination): string
    {
        return self::row($destination)['icon'];
    }

    public static function label(Destination $destination): string
    {
        return Lang::get('core::sidebar.nav.'.self::row($destination)['key']);
    }

    // A runtime that never registered a module's routes — the mobile peer boots
    // fewer of them — drops those rows from the rail instead of throwing.
    private static function isRegistered(Destination $destination): bool
    {
        return Container::getInstance()->make(Router::class)
            ->getRoutes()
            ->hasNamedRoute($destination->routeName());
    }

    /**
     * @return array{destination: Destination, icon: string, key: string, keywords: list<string>}
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
     * @return list<array{destination: Destination, icon: string, key: string, keywords: list<string>}>
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
     * @return list<array{destination: Destination, icon: string, key: string, keywords: list<string>}>
     */
    private static function overviewRows(): array
    {
        return [
            ['destination' => Destination::Dashboard, 'icon' => '◆', 'key' => 'dashboard', 'keywords' => ['home', 'main', 'this month', 'overview']],
            ['destination' => Destination::Transactions, 'icon' => '≡', 'key' => 'transactions', 'keywords' => ['txn', 'ledger', 'payments', 'spending']],
            ['destination' => Destination::Forecasts, 'icon' => '↗', 'key' => 'forecasts', 'keywords' => ['scenario', 'predict', 'projection', 'what if']],
            ['destination' => Destination::Calendar, 'icon' => '▦', 'key' => 'calendar', 'keywords' => ['bills', 'payments', 'balance', 'cash flow']],
            ['destination' => Destination::Notifications, 'icon' => '◈', 'key' => 'notifications', 'keywords' => ['alerts', 'unread', 'inbox', 'messages']],
        ];
    }

    /**
     * @return list<array{destination: Destination, icon: string, key: string, keywords: list<string>}>
     */
    private static function commitmentRows(): array
    {
        return [
            ['destination' => Destination::Recurring, 'icon' => '↻', 'key' => 'recurring', 'keywords' => ['series', 'fixed', 'standing order', 'direct debit']],
            ['destination' => Destination::Subscriptions, 'icon' => '▷', 'key' => 'subscriptions', 'keywords' => ['subscription', 'plans', 'memberships', 'price history']],
            ['destination' => Destination::Chains, 'icon' => '⇉', 'key' => 'chains', 'keywords' => ['routing', 'funding', 'credit card', 'settlement']],
            ['destination' => Destination::UnusualCharges, 'icon' => '◬', 'key' => 'unusual_charges', 'keywords' => ['anomaly', 'spike', 'unexpected', 'outlier']],
            ['destination' => Destination::DriftAlerts, 'icon' => '⚠', 'key' => 'drift_alerts', 'keywords' => ['drift', 'price rise', 'increase', 'alerts']],
        ];
    }

    /**
     * @return list<array{destination: Destination, icon: string, key: string, keywords: list<string>}>
     */
    private static function planningRows(): array
    {
        return [
            ['destination' => Destination::Budgets, 'icon' => '⊙', 'key' => 'budgets', 'keywords' => ['budget', 'envelope', 'limit', 'spending plan']],
            ['destination' => Destination::Tax, 'icon' => '⊞', 'key' => 'tax', 'keywords' => ['deduction', 'aangifte', 'export', 'records']],
            ['destination' => Destination::Goals, 'icon' => '◎', 'key' => 'goals', 'keywords' => ['goal', 'saving', 'target', 'milestone']],
            ['destination' => Destination::Pots, 'icon' => '◫', 'key' => 'pots', 'keywords' => ['pot', 'jar', 'set aside', 'sinking fund']],
        ];
    }

    /**
     * @return list<array{destination: Destination, icon: string, key: string, keywords: list<string>}>
     */
    private static function insightRows(): array
    {
        return [
            ['destination' => Destination::Reports, 'icon' => '▤', 'key' => 'reports', 'keywords' => ['report', 'chart', 'analysis', 'library']],
            ['destination' => Destination::Reconcile, 'icon' => '✓', 'key' => 'reconcile', 'keywords' => ['reconcile', 'statement', 'balance', 'check']],
        ];
    }

    /**
     * @return list<array{destination: Destination, icon: string, key: string, keywords: list<string>}>
     */
    private static function ingestionRows(): array
    {
        return [
            ['destination' => Destination::Imports, 'icon' => '⊕', 'key' => 'imports', 'keywords' => ['upload', 'csv', 'mt940', 'camt', 'statement']],
            ['destination' => Destination::CashBook, 'icon' => '€', 'key' => 'cashbook', 'keywords' => ['cash', 'petty cash', 'manual', 'wallet']],
            ['destination' => Destination::Email, 'icon' => '✉️', 'key' => 'email', 'keywords' => ['inbox', 'gmail', 'imap', 'mail', 'senders', 'receipts']],
        ];
    }

    /**
     * @return list<array{destination: Destination, icon: string, key: string, keywords: list<string>}>
     */
    private static function organiseRows(): array
    {
        return [
            ['destination' => Destination::Counterparties, 'icon' => '◉', 'key' => 'counterparties', 'keywords' => ['merchant', 'payee', 'vendor', 'shop']],
            ['destination' => Destination::Triage, 'icon' => '❋', 'key' => 'triage', 'keywords' => ['unknown', 'queue', 'identify', 'merchant']],
            ['destination' => Destination::Categorization, 'icon' => '⌕', 'key' => 'categorization', 'keywords' => ['category', 'rules', 'tag', 'uncategorized']],
            ['destination' => Destination::Community, 'icon' => '◇', 'key' => 'community', 'keywords' => ['shared', 'mystery merchant', 'contribute']],
        ];
    }

    // The sidebar draws Data & Devices as an inline SVG, so this glyph is the
    // palette's alone.
    /**
     * @return list<array{destination: Destination, icon: string, key: string, keywords: list<string>}>
     */
    private static function settingsRows(): array
    {
        return [
            ['destination' => Destination::DataDevices, 'icon' => '⇄', 'key' => 'data_devices', 'keywords' => ['sync', 'pair', 'device', 'backup', 'phone']],
            ['destination' => Destination::DataLocations, 'icon' => '⌖', 'key' => 'data_locations', 'keywords' => ['where', 'path', 'folder', 'storage', 'export', 'delete']],
            ['destination' => Destination::Settings, 'icon' => '⚙️', 'key' => 'settings', 'keywords' => ['preferences', 'config', 'profile', 'account', 'language']],
        ];
    }
}
