<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Detectors;

use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\DetectsStartingBalance;

// PayPal's per-event `Saldo` resets to zero after every funding sweep, so
// it carries no opening-balance signal. Always empty, never a 0-value
// candidate: the user would confirm that and lose their real balance.
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
