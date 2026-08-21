<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;
use stdClass;

// The consumer branches on total === 0 against emptyMessage !== null: the
// first is a real empty result, the second a pattern too short to scan.
final class AliasMatchPreviewResultDto extends Data
{
    /**
     * @param  list<stdClass>  $first5
     */
    public function __construct(
        public readonly int $total,
        public readonly array $first5,
        public readonly ?string $emptyMessage = null,
    ) {}

    /**
     * @param  list<stdClass>  $first5
     */
    public static function withMatches(int $total, array $first5): self
    {
        return new self(
            total: $total,
            first5: $first5,
            emptyMessage: null,
        );
    }

    public static function withoutMatches(string $message): self
    {
        return new self(
            total: 0,
            first5: [],
            emptyMessage: $message,
        );
    }
}
