<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One authenticated app view the command palette can jump to.
 *
 * `id` is the stable client-side identifier Fuse.js uses for keying
 * (`:key="hit.item.id"` in the palette modal); `label` is the visible
 * row text; `hint` is the secondary line (typically the route's
 * purpose); `icon` is the leading glyph; `url` is the navigation
 * target; `keywords` extend the Fuse.js scoring surface beyond the
 * label (e.g. ["receipts", "imports"] for the "Email" view).
 *
 * Concrete population happens in 16-08 (palette plan); the registry
 * binding is replaced from `NullNavigationRegistry` to
 * `NavigationRegistryImpl` inside `DevModeServiceProvider::register()`
 * at that point.
 *
 * @param  list<string>  $keywords
 */
final class NavigationEntry extends Data
{
    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $hint,
        public readonly string $icon,
        public readonly string $url,
        public readonly array $keywords = [],
    ) {}
}
