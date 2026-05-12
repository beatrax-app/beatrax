<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Contracts;

use Modules\Ingestion\Public\Dto\AccountResolution;

/**
 * Maps an IBAN string to an Account: either Known(accountId) for IBANs that
 * already have a row, or Unknown(iban) so the upload wizard can pause for
 * the user to name the account before proceeding.
 *
 * The default implementation lives in the Import module; adapter tests pass
 * in lightweight in-memory implementations.
 */
interface AccountResolver
{
    public function resolve(string $iban): AccountResolution;
}
