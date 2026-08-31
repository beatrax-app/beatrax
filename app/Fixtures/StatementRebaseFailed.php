<?php

declare(strict_types=1);

namespace App\Fixtures;

use RuntimeException;

// Narrower than the RuntimeException it extends so the command can print its
// message: a broad catch could be handed a QueryException, whose message
// carries the row that failed.
final class StatementRebaseFailed extends RuntimeException {}
