<?php

declare(strict_types=1);
use Modules\Categorization\Providers\CategorizationServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;
use Modules\Import\Providers\ImportServiceProvider;
use Modules\Ingestion\Providers\IngestionServiceProvider;
use Modules\Ledger\Providers\LedgerServiceProvider;

return [
    CoreServiceProvider::class,
    LedgerServiceProvider::class,
    IngestionServiceProvider::class,
    ImportServiceProvider::class,
    CategorizationServiceProvider::class,
];
