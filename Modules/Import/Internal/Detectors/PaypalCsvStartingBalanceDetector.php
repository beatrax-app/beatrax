<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Detectors;

use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\DetectsStartingBalance;

/**
 * Starting-balance detector for PayPal Activity Download CSV exports
 * (`source_format = 'paypal-csv'`).
 *
 * PayPal Activity CSV carries no usable opening-balance signal — the
 * per-event `Saldo` cell resets to zero after every funding sweep,
 * so the first row's value is meaningless as a starting balance.
 * This detector intentionally returns an empty list so the wizard
 * renders the PayPal account's starting-balance card in manual-entry
 * state.
 *
 * Returning an empty list is the contract — never a candidate with
 * `openingBalanceMinor = 0`, which would be silently confirmed and
 * lose the user's actual opening balance.
 */
final class PaypalCsvStartingBalanceDetector implements DetectsStartingBalance
{
    private const SOURCE_FORMAT = 'paypal-csv';

    public function supports(string $sourceFormat): bool
    {
        return $sourceFormat === self::SOURCE_FORMAT;
    }

    public function detect(array $importRunIds, User $user): array
    {
        unset($importRunIds, $user);

        return [];
    }
}
