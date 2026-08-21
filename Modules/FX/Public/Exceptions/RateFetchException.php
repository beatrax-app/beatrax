<?php

declare(strict_types=1);

namespace Modules\FX\Public\Exceptions;

use RuntimeException;

// Recoverable per-provider failure: the registry catches it and falls through to
// the next provider, raising AllProvidersFailed once the chain is exhausted.
final class RateFetchException extends RuntimeException {}
