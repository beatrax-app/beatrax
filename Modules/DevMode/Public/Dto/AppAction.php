<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Spatie\LaravelData\Data;

// Exactly one of `handlerEvent` or `url` is non-null — the palette dispatches
// the one it finds. `id` doubles as the per-user Recent-shortcuts cache key.
// The two visible strings ride as KEYS: the registry is a container singleton,
// so a resolved word would be whichever language first built it.
final class AppAction extends Data
{
    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public readonly string $id,
        public readonly string $labelKey,
        public readonly string $hintKey,
        public readonly string $icon,
        public readonly ?string $handlerEvent,
        public readonly ?string $url,
        public readonly array $keywords = [],
    ) {}
}
