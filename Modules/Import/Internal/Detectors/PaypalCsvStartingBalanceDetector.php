<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Detectors;

use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\DetectsStartingBalance;

// PayPal's per-event `Saldo` cell resets to zero after every funding
// sweep, so it carries no usable opening-balance signal — this detector
// always returns an empty list (never a 0-value candidate, which would
// be silently confirmed and lose the user's actual opening balance).
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
