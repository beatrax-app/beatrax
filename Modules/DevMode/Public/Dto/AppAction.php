<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Spatie\LaravelData\Data;

// A row is a destination and nothing else: two rows once named a browser event
// instead, nothing in the tree listened, and the pick was filed under Recent
// while nothing ran. `id` doubles as the Recent cache key. The visible strings
// ride as KEYS — the singleton would otherwise freeze the first reader's words.
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
        public readonly string $url,
        public readonly array $keywords = [],
    ) {}
}
