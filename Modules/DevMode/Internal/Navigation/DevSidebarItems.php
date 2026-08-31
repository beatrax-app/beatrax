<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Navigation;

// `enabled` is informational only: the layout gates nav-disabled on
// Router::has() at render time, so drift from this list shows up rather than
// being masked.
/**
 * @phpstan-type DevSidebarItem array{slug: string, labelKey: string, icon: string, route: string, enabled: bool|string}
 */
final class DevSidebarItems
{
    // true = route registered; false = placeholder; 'conditional' = only when
    // the named route resolves at render time.

    // The label rides as a KEY. This class is a container singleton and the
    // list is a const, so a word resolved here would be whichever language
    // first built it; dev-shell.blade.php reads it through Lang::get().

    // Doctor's gear ends in an invisible U+FE0F, so both phone engines agree it
    // is a picture rather than a glyph.
    /**
     * @var list<DevSidebarItem>
     *
     * @link ../../../../.docs/conventions/emoji-presentation-selector.md
     */
    private const array ITEMS = [
        ['slug' => 'overview', 'labelKey' => 'dev::nav.overview', 'icon' => '◆', 'route' => 'dev.overview', 'enabled' => true],
        ['slug' => 'artisan',  'labelKey' => 'dev::nav.artisan',  'icon' => '›_', 'route' => 'dev.artisan', 'enabled' => true],
        ['slug' => 'audit',    'labelKey' => 'dev::nav.audit',    'icon' => '⌗',  'route' => 'dev.audit', 'enabled' => true],
        ['slug' => 'logs',     'labelKey' => 'dev::nav.logs',     'icon' => '≡',  'route' => 'dev.logs', 'enabled' => true],
        ['slug' => 'queue',    'labelKey' => 'dev::nav.queue',    'icon' => '↻',  'route' => 'dev.queue', 'enabled' => true],
        ['slug' => 'doctor',   'labelKey' => 'dev::nav.doctor',   'icon' => '⚙️',  'route' => 'dev.doctor', 'enabled' => true],
        ['slug' => 'sql',      'labelKey' => 'dev::nav.sql',      'icon' => '⌕',  'route' => 'dev.sql', 'enabled' => true],
        ['slug' => 'horizon',  'labelKey' => 'dev::nav.horizon',  'icon' => '↗',  'route' => 'dev.horizon', 'enabled' => 'conditional'],
        ['slug' => 'system',   'labelKey' => 'dev::nav.system',   'icon' => '◇',  'route' => 'dev.system', 'enabled' => true],
        // Registered by the Sync module, so a build shipped without Sync
        // degrades to nav-disabled at render time.
        ['slug' => 'sync-health', 'labelKey' => 'dev::nav.sync_health', 'icon' => '⇄', 'route' => 'dev.sync-health', 'enabled' => true],
    ];

    /**
     * @return list<DevSidebarItem>
     */
    public function all(): array
    {
        return self::ITEMS;
    }
}
