<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Navigation;

// `enabled` is informational only: the layout gates nav-disabled on
// Router::has() at render time, so drift from this list shows up rather than
// being masked.
/**
 * @phpstan-type DevSidebarItem array{slug: string, label: string, icon: string, route: string, enabled: bool|string}
 */
final class DevSidebarItems
{
    // true = route registered; false = placeholder; 'conditional' = only when
    // the named route resolves at render time.
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
        // Registered by the Sync module, so a build shipped without Sync
        // degrades to nav-disabled at render time.
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
