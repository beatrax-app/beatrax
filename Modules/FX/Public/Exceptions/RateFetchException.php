<?php

declare(strict_types=1);

namespace Modules\FX\Public\Exceptions;

use RuntimeException;

// Thrown by a RateProvider implementation when it cannot return rates
// (network error, unexpected response format, auth failure). The
// registry catches this and moves on to the next provider in the
// fallback chain; AllProvidersFailed is thrown once all are exhausted.
final class RateFetchException extends RuntimeException {}
