<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Navigation;

/**
 * Registry of Dev Console sidebar items.
 *
 * Single source of truth for the dev-shell layout's sidebar nav-item
 * list. Each entry carries the slug, label, icon, route name, and an
 * `enabled` field that documents the intended state of the matching
 * route.
 *
 * The dev-shell layout reads this service for the *labels*, *slugs*,
 * and *icons*, but continues to gate the `nav-disabled` class on
 * Router::has('dev.{slug}') at render time. The `enabled` field is
 * informational: drift between this constant and the live router
 * resolves at render time — if a slug is marked enabled here but its
 * route is absent, the layout still shows nav-disabled. The dual
 * representation surfaces config drift instead of masking it.
 *
 * @phpstan-type DevSidebarItem array{slug: string, label: string, icon: string, route: string, enabled: bool|string}
 */
final class DevSidebarItems
{
    /**
     * Canonical list. The order is the sidebar render order.
     *
     * `enabled`:
     *   - true          — route should be registered; the sidebar
     *                     renders the item without nav-disabled.
     *   - false         — route is not yet registered (placeholder).
     *   - 'conditional' — enabled only when the named route resolves
     *                     at render time (e.g. the Horizon iframe,
     *                     which depends on a require-dev package).
     *
     * @var list<DevSidebarItem>
     */
    private const ITEMS = [
        ['slug' => 'overview', 'label' => 'Overview', 'icon' => '◆', 'route' => 'dev.overview', 'enabled' => true],
        ['slug' => 'artisan',  'label' => 'Artisan',  'icon' => '›_', 'route' => 'dev.artisan', 'enabled' => true],
        ['slug' => 'audit',    'label' => 'Audit',    'icon' => '⌗',  'route' => 'dev.audit', 'enabled' => true],
        ['slug' => 'logs',     'label' => 'Logs',     'icon' => '≡',  'route' => 'dev.logs', 'enabled' => true],
        ['slug' => 'queue',    'label' => 'Queue',    'icon' => '↻',  'route' => 'dev.queue', 'enabled' => true],
        ['slug' => 'doctor',   'label' => 'Doctor',   'icon' => '⚙',  'route' => 'dev.doctor', 'enabled' => true],
        ['slug' => 'sql',      'label' => 'SQL',      'icon' => '⌕',  'route' => 'dev.sql', 'enabled' => true],
        ['slug' => 'horizon',  'label' => 'Horizon',  'icon' => '↗',  'route' => 'dev.horizon', 'enabled' => 'conditional'],
        ['slug' => 'system',   'label' => 'System',   'icon' => '◇',  'route' => 'dev.system', 'enabled' => true],
        // The sync-health route lives in the Sync module; gated here via
        // Router::has('dev.sync-health') at render time, so a build that
        // ships without the Sync module degrades to nav-disabled.
        ['slug' => 'sync-health', 'label' => 'Sync Health', 'icon' => '⇄', 'route' => 'dev.sync-health', 'enabled' => true],
    ];

    /**
     * @return list<DevSidebarItem>
     */
    public function all(): array
    {
        return self::ITEMS;
    }
}
