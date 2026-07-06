<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One parsed source account (YNAB4/nYNAB's `Account` register column value,
 * or an Actual `accounts` row read through the app's own account list).
 *
 * `sourceExternalId` is the source-native id for Actual (a real UUID); for
 * YNAB4/nYNAB (no per-account id in the CSV export) the parser falls back to
 * the normalized account-name string itself as the natural key (D-10 — YNAB
 * account names are NOT guaranteed globally unique across a household's
 * lifetime, but are the only signal the CSV export carries).
 */
final class MigrationAccountDto extends Data
{
    public function __construct(
        public readonly string $sourceExternalId,
        public readonly string $name,
        public readonly string $currency,
        public readonly ?string $kind = null,
    ) {}
}
