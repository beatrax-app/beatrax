<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One argument the artisan runner renders an input for.
 *
 * `name` is the argument's stable identifier (e.g. `since`, `--user`).
 * `type` is the input widget shape (`string`, `int`, `bool`, `enum`).
 * `required` flags whether the runner blocks submit until the user
 * supplies a value. `default` carries a pre-filled value; for `enum`
 * `options` is the allow-list the dropdown renders.
 *
 * @param  list<string>|null  $options
 */
final class ArgSpec extends Data
{
    /**
     * @param  list<string>|null  $options
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        /** 'string' | 'int' | 'bool' | 'enum' */
        public readonly string $type,
        public readonly bool $required = false,
        public readonly mixed $default = null,
        public readonly ?array $options = null,
    ) {}
}
