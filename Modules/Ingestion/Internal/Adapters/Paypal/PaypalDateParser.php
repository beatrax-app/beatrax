<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;

/**
 * Parses PayPal Activity Download Datum cells into a startOfDay
 * `CarbonImmutable`.
 *
 * The startOfDay normalisation matches the project-wide
 * FingerprintComposer v3 day-precision invariant established by the
 * ASN CSV / CAMT.053 / MT940 / ICS PDF adapters, so cross-format dedup
 * hashes remain comparable.
 *
 * Empirical shape sourced from the Wave 0 fixture: PayPal exports
 * the Datum column as M/D/YYYY (US numeric date), independent of the
 * account's display locale. The ISO `yyyy-mm-dd` shape is accepted as a
 * forward-compatibility fallback for the case where a future PayPal
 * export shifts to that shape.
 *
 * Stateless; no global locale mutation.
 */
final class PaypalDateParser
{
    public function parse(string $raw): CarbonImmutable
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw new InvalidAmountException('Empty PayPal date string.');
        }

        // Shape A: M/D/YYYY (US numeric — the empirical PayPal Activity
        // Download shape, regardless of account display locale).
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $trimmed) === 1) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!n/j/Y', $trimmed);
            } catch (InvalidFormatException $e) {
                throw new InvalidAmountException(sprintf(
                    "Cannot parse PayPal date: '%s' (%s)",
                    $raw,
                    $e->getMessage(),
                ));
            }

            if ($parsed instanceof CarbonImmutable) {
                return $parsed->startOfDay();
            }
        }

        // Shape B: YYYY-MM-DD (ISO 8601 fallback for future PayPal
        // export shape drift).
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $trimmed);
            } catch (InvalidFormatException $e) {
                throw new InvalidAmountException(sprintf(
                    "Cannot parse PayPal date: '%s' (%s)",
                    $raw,
                    $e->getMessage(),
                ));
            }

            if ($parsed instanceof CarbonImmutable) {
                return $parsed->startOfDay();
            }
        }

        throw new InvalidAmountException(sprintf(
            "Cannot parse PayPal date: '%s' (expected M/D/YYYY or YYYY-MM-DD)",
            $raw,
        ));
    }
}
