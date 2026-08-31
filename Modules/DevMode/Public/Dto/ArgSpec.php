<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Modules\DevMode\Internal\Enums\ArgType;
use Spatie\LaravelData\Data;

// `rules` drives both the rendered widget and the server-side validation, so
// the two cannot drift. `options` is required only for ArgType::Select, and
// holds the literal values artisan accepts rather than anything a reader reads.
// The three visible strings ride as KEYS, for the reason CommandSpec gives.
final class ArgSpec extends Data
{
    /**
     * @param  list<string>  $rules
     * @param  list<string>|null  $options
     */
    public function __construct(
        public readonly string $name,
        public readonly string $labelKey,
        public readonly ArgType $type,
        public readonly array $rules,
        public readonly ?string $placeholderKey = null,
        public readonly ?string $helpTextKey = null,
        public readonly ?array $options = null,
    ) {}
}
