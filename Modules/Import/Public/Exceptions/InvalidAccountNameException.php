<?php

declare(strict_types=1);

namespace Modules\Import\Public\Exceptions;

use InvalidArgumentException;

// Thrown by AccountNamer when the trimmed name is empty or exceeds the
// multibyte length bound — kept in the service layer (not only Livewire
// rules()) so every entrypoint (CLI, programmatic, future REST) applies
// the same constraint.
final class InvalidAccountNameException extends InvalidArgumentException {}
