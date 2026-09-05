<?php

declare(strict_types=1);

namespace Modules\Receipts\Tests\Support;

use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;

final class ChainHintFromReceiptTestAccountResolver implements AccountResolver
{
    public function resolve(string $iban): AccountResolution
    {
        // The spy test only iterates the row generator and never writes, so any
        // non-null account id will do.
        return AccountResolution::known(1);
    }
}
