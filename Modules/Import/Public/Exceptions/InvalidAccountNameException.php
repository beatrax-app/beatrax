<?php

declare(strict_types=1);

namespace Modules\Import\Public\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by AccountNamer when the user-supplied account name is empty
 * after trimming, or exceeds the multibyte length bound. The wizard
 * catches it and surfaces the message next to the input via Livewire's
 * error bag; CLI callers get the message in stderr.
 *
 * Keeping the bound in the service layer (rather than only in the
 * Livewire `rules()`) ensures every entrypoint — CLI, programmatic,
 * future REST — applies the same constraint.
 */
final class InvalidAccountNameException extends InvalidArgumentException {}
