<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Spatie\LaravelData\Data;

// `id` is the stable client-side identifier Fuse.js uses for keying;
// `keywords` extend the Fuse.js scoring surface beyond the label (e.g.
// ["receipts", "imports"] for the "Email" view). The curated list of
// entries lives in NavigationRegistryImpl.
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
