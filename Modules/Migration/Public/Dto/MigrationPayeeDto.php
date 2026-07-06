<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One parsed source payee (a distinct merchant/person name string). Never
 * emitted for a transfer's synthetic counterpart-account "payee" (YNAB4/
 * nYNAB's `"Transfer : <Account>"` convention, Actual's `payees.transfer_acct`
 * rows) — those are resolved into `MigrationTransactionDto::$
 * transferCounterpartSourceExternalId` instead, matching the codebase's
 * existing convention that a transfer leg has no real counterparty
 * (`Modules\Counterparties`'s `self_account`/transfer handling).
 *
 * `sourceExternalId` is nullable: Actual carries a real payee UUID, but
 * YNAB4/nYNAB CSV rows identify a payee only by its display-name string —
 * the natural-key fallback (D-10) resolves those against `name` alone.
 */
final class MigrationPayeeDto extends Data
{
    public function __construct(
        public readonly ?string $sourceExternalId,
        public readonly string $name,
    ) {}
}
