<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Navigation;

// Single source of truth for the dev-shell sidebar nav-item list. The
// layout still gates `nav-disabled` on Router::has('dev.{slug}') at
// render time — `enabled` is informational only, so drift between it
// and the live router surfaces config drift rather than masking it.
/**
 * @phpstan-type DevSidebarItem array{slug: string, label: string, icon: string, route: string, enabled: bool|string}
 */
final class DevSidebarItems
{
    // `enabled`: true = route registered; false = not yet registered
    // (placeholder); 'conditional' = enabled only when the named route
    // resolves at render time (e.g. the require-dev-gated Horizon iframe).
    /**
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
