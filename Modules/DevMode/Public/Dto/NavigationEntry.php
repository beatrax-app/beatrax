<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Spatie\LaravelData\Data;

// `keywords` widen the Fuse.js scoring surface past the label — the way
// "receipts" or "imports" reaches the "Email" view.
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
