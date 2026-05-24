<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One named app action the command palette can fire.
 *
 * App actions are named cross-app menu entries the palette also
 * exposes ("Sign out", "Open inbox", "Switch theme to dark", etc.).
 * Exactly one of `handlerEvent` or `url` is non-null: the palette
 * dispatches the Livewire browser event for handlerEvent-shaped
 * rows, or it navigates the focused window to `url` for url-shaped
 * rows.
 *
 * `id` is the stable client-side identifier Fuse.js uses for keying
 * (`:key="hit.item.id"` in the palette modal) AND the cache key
 * for the per-user Recent-shortcuts list. `keywords` extends the
 * Fuse.js scoring surface beyond the label (e.g. ["logout", "quit"]
 * for "Sign out"). Population lives in AppActionRegistryImpl.
 *
 * @param  list<string>  $keywords
 */
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
