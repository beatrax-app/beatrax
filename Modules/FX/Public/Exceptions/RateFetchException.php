<?php

declare(strict_types=1);

namespace Modules\FX\Public\Exceptions;

use RuntimeException;

/**
 * Thrown by a RateProvider implementation when it cannot return rates
 * (network error, unexpected response format, authentication failure, etc.).
 *
 * The ExchangeRateService catches this exception and moves on to the
 * next provider in the fallback chain. When all providers fail it
 * throws AllProvidersFailed instead.
 */
final class RateFetchException extends RuntimeException {}
