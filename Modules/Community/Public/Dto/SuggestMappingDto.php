<?php

declare(strict_types=1);

namespace Modules\Community\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Immutable payload assembled by the SuggestMappingModal before it
 * hands off to GitHubCompareUrlBuilder + OpenExternalUrlAction. The
 * region defaults to `'NL'` because the bundled corpus targets Dutch
 * banks; the dropdown in the modal lets the user override.
 */
final class SuggestMappingDto extends Data
{
    public function __construct(
        public readonly string $pattern,
        public readonly string $name,
        public readonly ?string $category = null,
        public readonly string $region = 'NL',
    ) {}
}
