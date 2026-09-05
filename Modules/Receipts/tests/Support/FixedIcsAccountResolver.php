<?php

declare(strict_types=1);

namespace Modules\Receipts\Tests\Support;

use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;

// IcsPdfAdapter resolves the own-IBAN once before it starts iterating and does
// nothing with the answer, so a non-throwing implementation is all this arm needs.
final class FixedIcsAccountResolver implements AccountResolver
{
    public function __construct(private readonly int $accountId) {}

    public function resolve(string $iban): AccountResolution
    {
        return AccountResolution::known($this->accountId);
    }
}
