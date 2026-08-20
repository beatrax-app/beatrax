<?php

declare(strict_types=1);

namespace Modules\Import\Public\Exceptions;

use InvalidArgumentException;

// Raised in the service layer rather than only in Livewire rules(), so the
// CLI and programmatic entrypoints apply the same bound.
final class InvalidAccountNameException extends InvalidArgumentException {}
