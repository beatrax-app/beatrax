<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Spatie\LaravelData\Data;

// Exactly one of `handlerEvent` or `url` is non-null: the palette
// dispatches the Livewire browser event for handlerEvent-shaped rows,
// or navigates the focused window to `url` for url-shaped rows. `id`
// is also the cache key for the per-user Recent-shortcuts list.
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
