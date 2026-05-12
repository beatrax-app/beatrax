<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Discriminated union for the result of an AccountResolver lookup.
 *
 * Use one of:
 *   - AccountResolution::known(int $accountId)   → KnownAccount
 *   - AccountResolution::unknown(string $iban)   → UnknownAccount
 *
 * The upload wizard inspects the concrete type to decide whether to prompt
 * the user to name a new account before proceeding to the preview screen.
 */
abstract class AccountResolution extends Data
{
    public static function known(int $accountId): KnownAccount
    {
        return new KnownAccount($accountId);
    }

    public static function unknown(string $iban): UnknownAccount
    {
        return new UnknownAccount($iban);
    }
}
