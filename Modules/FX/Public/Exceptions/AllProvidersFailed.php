<?php

declare(strict_types=1);

namespace Modules\FX\Public\Exceptions;

use RuntimeException;

/**
 * Thrown by the RateProviderRegistry when every provider in the fallback
 * chain raises a RateFetchException. This is a terminal failure: no rate
 * is available and no conversion can be performed.
 *
 * Callers that need a safe fallback should catch AllProvidersFailed and
 * apply the passthrough path (D-07 — convert only when a rate exists).
 */
final class AllProvidersFailed extends RuntimeException {}
