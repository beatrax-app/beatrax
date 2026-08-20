<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Modules\Ingestion\Public\Exceptions\InvalidDateException;

final class PaypalDateParser
{
    public function parse(string $raw): CarbonImmutable
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw new InvalidDateException('Empty PayPal date string.');
        }

        // The Activity Download ships US numeric M/D/YYYY whatever the account's display locale.
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $trimmed) === 1) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!n/j/Y', $trimmed);
            } catch (InvalidFormatException $e) {
                throw new InvalidDateException(sprintf(
                    "Cannot parse PayPal date: '%s' (%s)",
                    $raw,
                    $e->getMessage(),
                ));
            }

            if ($parsed instanceof CarbonImmutable) {
                return $parsed->startOfDay();
            }
        }

        // ISO 8601 fallback for PayPal export shape drift.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $trimmed);
            } catch (InvalidFormatException $e) {
                throw new InvalidDateException(sprintf(
                    "Cannot parse PayPal date: '%s' (%s)",
                    $raw,
                    $e->getMessage(),
                ));
            }

            if ($parsed instanceof CarbonImmutable) {
                return $parsed->startOfDay();
            }
        }

        throw new InvalidDateException(sprintf(
            "Cannot parse PayPal date: '%s' (expected M/D/YYYY or YYYY-MM-DD)",
            $raw,
        ));
    }
}
