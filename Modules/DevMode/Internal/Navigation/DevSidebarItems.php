<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Navigation;

/**
 * Registry of Dev Console sidebar items.
 *
 * 16-06 introduces this service as the future single source of truth
 * for the dev-shell layout's sidebar nav-item list. It establishes the
 * pattern Wave 5 / Wave 7 plans use to flip their slug's `enabled` bit
 * to `true` as their routes land — each owning plan edits exactly one
 * line in the ITEMS constant below (W-3 fix).
 *
 * The dev-shell layout today reads this service for the *labels* +
 * *slugs* + *icons*, but continues to gate the `nav-disabled` class on
 * `Route::has('dev.{slug}')` at render time. The `enabled` field in
 * the constant is informational — it documents the design contract:
 * "the route MUST be registered by the plan that flips this slug's
 * value to true". Drift between the constant and Route::has resolves
 * naturally at render: if a slug is marked enabled here but its route
 * is absent, the layout still shows nav-disabled (the runtime truth
 * wins). The W-3 directive explicitly chooses this dual representation
 * over a strict allow-list because the allow-list would mask config
 * drift.
 *
 * @phpstan-type DevSidebarItem array{slug: string, label: string, icon: string, route: string, enabled: bool|string}
 */
final class DevSidebarItems
{
    /**
     * Canonical list. The order is the sidebar render order.
     *
     * `enabled`:
     *   - true        — the owning plan has landed; the route is
     *                   registered + the sidebar should render the
     *                   item without nav-disabled.
     *   - false       — the owning plan has not yet landed.
     *   - 'conditional' — only enabled when {@see Route::has}
     *                     resolves at render time (D-38 two-signal
     *                     for the Horizon iframe).
     *
     * @var list<DevSidebarItem>
     */
    private const ITEMS = [
        ['slug' => 'overview', 'label' => 'Overview', 'icon' => '◆', 'route' => 'dev.overview', 'enabled' => true],   // 16-03
        ['slug' => 'artisan',  'label' => 'Artisan',  'icon' => '›_', 'route' => 'dev.artisan', 'enabled' => true],   // 16-04b
        ['slug' => 'audit',    'label' => 'Audit',    'icon' => '⌗',  'route' => 'dev.audit', 'enabled' => true],     // 16-04b
        ['slug' => 'logs',     'label' => 'Logs',     'icon' => '≡',  'route' => 'dev.logs', 'enabled' => true],      // 16-05
        ['slug' => 'queue',    'label' => 'Queue',    'icon' => '↻',  'route' => 'dev.queue', 'enabled' => true],     // 16-06 (THIS plan)
        ['slug' => 'doctor',   'label' => 'Doctor',   'icon' => '⚙',  'route' => 'dev.doctor', 'enabled' => true],    // 16-07 Task 2
        ['slug' => 'sql',      'label' => 'SQL',      'icon' => '⌕',  'route' => 'dev.sql', 'enabled' => true],       // 16-07 Task 3
        ['slug' => 'horizon',  'label' => 'Horizon',  'icon' => '↗',  'route' => 'dev.horizon', 'enabled' => 'conditional'], // D-38 two-signal — THIS plan
        ['slug' => 'system',   'label' => 'System',   'icon' => '◇',  'route' => 'dev.system', 'enabled' => true],    // 16-07 Task 2
    ];

    /**
     * @return list<DevSidebarItem>
     */
    public function all(): array
    {
        return self::ITEMS;
    }
}
