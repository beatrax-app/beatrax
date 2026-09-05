<?php

declare(strict_types=1);

namespace Modules\Receipts\Tests\Support;

use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\KnownAccount;

// The fingerprint tuple includes accountId, so both sides only have to agree on
// the id for the two hashes to collapse onto each other.
final class FixedPaypalAccountResolver implements AccountResolver
{
    public function __construct(private readonly int $accountId) {}

    public function resolve(string $iban): KnownAccount
    {
        return new KnownAccount($this->accountId);
    }
}
