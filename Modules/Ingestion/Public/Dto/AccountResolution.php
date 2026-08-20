<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Dto;

use Spatie\LaravelData\Data;

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
