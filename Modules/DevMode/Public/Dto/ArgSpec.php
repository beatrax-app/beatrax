<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Spatie\LaravelData\Data;

// `rules` drives both the rendered widget and the server-side validation, so
// the two cannot drift. `options` is required only for type 'select'.
final class ArgSpec extends Data
{
    /**
     * @param  list<string>  $rules
     * @param  list<string>|null  $options
     * @param  'text'|'select'|'file-path'|'boolean'  $type
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly array $rules,
        public readonly ?string $placeholder = null,
        public readonly ?string $helpText = null,
        public readonly ?array $options = null,
    ) {}
}
