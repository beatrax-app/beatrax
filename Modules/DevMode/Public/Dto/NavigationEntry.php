<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One authenticated app view the command palette can jump to.
 *
 * `label` is the visible row text; `hint` is the secondary line
 * (typically the route's purpose); `icon` is the leading glyph; `url`
 * is the navigation target; `keywords` extend the Fuse.js scoring
 * surface beyond the label (e.g. ["receipts", "imports"] for the
 * "Email" view).
 *
 * Concrete population happens in 16-08 (palette plan); this module's
 * `NullNavigationRegistry` returns an empty list so the binding shape
 * is in place from day one.
 *
 * @param  list<string>  $keywords
 */
final class NavigationEntry extends Data
{
    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public readonly string $label,
        public readonly string $hint,
        public readonly string $icon,
        public readonly string $url,
        public readonly array $keywords = [],
    ) {}
}
