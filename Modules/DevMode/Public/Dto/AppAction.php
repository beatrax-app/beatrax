<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Spatie\LaravelData\Data;

// Exactly one of `handlerEvent` or `url` is non-null — the palette dispatches
// the one it finds. `id` doubles as the per-user Recent-shortcuts cache key.
final class AppAction extends Data
{
    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $hint,
        public readonly string $icon,
        public readonly ?string $handlerEvent,
        public readonly ?string $url,
        public readonly array $keywords = [],
    ) {}
}
