<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Contracts;

use Modules\Ingestion\Public\Dto\AccountResolution;

/**
 * @link ../../../../.docs/features/ingestion/architecture.md
 */
interface AccountResolver
{
    public function resolve(string $iban): AccountResolution;
}
