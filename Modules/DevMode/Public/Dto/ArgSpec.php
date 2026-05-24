<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Declarative schema for one artisan-command argument the Dev Console
 * renders an input for.
 *
 * The runner page reads the argsSchema list from a
 * CommandSpec and emits a Flux form widget per ArgSpec entry; the
 * spawn endpoint validates the submitted values against the same
 * schema before the process is spawned. The `rules` field carries a
 * standard Laravel validation array so server-side enforcement is
 * one `validate()` call away.
 *
 * `type` is the widget shape:
 *   - `text`       — single-line text input (default).
 *   - `select`     — dropdown bound to the `options` list.
 *   - `file-path`  — text input the runner UI hints as a file path;
 *                    backed by a `string` rule on the server.
 *   - `boolean`    — checkbox; runner UI renders a toggle.
 *
 * `options` is required for `type === 'select'` and ignored for the
 * other types. The runner submits the selected string value verbatim.
 *
 * `rules` is the Laravel validation rule list the controller applies
 * to the submitted value (e.g. `['required', 'string', 'max:255']`).
 * Even arguments documented as optional pass through this list — an
 * empty rule list is the explicit "no constraints" marker.
 *
 * @param  list<string>  $rules
 * @param  list<string>|null  $options
 */
final class ArgSpec extends Data
{
    /**
     * @param  list<string>  $rules
     * @param  list<string>|null  $options
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        /** 'text' | 'select' | 'file-path' | 'boolean' */
        public readonly string $type,
        public readonly array $rules,
        public readonly ?string $placeholder = null,
        public readonly ?string $helpText = null,
        public readonly ?array $options = null,
    ) {}
}
