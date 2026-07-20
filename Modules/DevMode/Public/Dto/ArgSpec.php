<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Spatie\LaravelData\Data;

// Declarative schema for one artisan-command argument; the runner page
// reads argsSchema off a CommandSpec and emits a Flux widget per entry,
// and the spawn endpoint validates submitted values against the same
// `rules` before spawning. `options` is required only for type==='select'.
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
