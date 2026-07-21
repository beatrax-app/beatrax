<?php

declare(strict_types=1);

namespace Modules\FX\Public\Exceptions;

use RuntimeException;

// Thrown by RateProviderRegistry when every provider in the fallback
// chain raises a RateFetchException - a terminal failure with no rate
// available. Callers that need a safe fallback should catch it and
// apply the passthrough path instead of converting.
final class AllProvidersFailed extends RuntimeException {}
