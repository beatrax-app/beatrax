<?php

declare(strict_types=1);

use App\Providers\HorizonServiceProvider;
use Modules\Categorization\Providers\CategorizationServiceProvider;
use Modules\Chains\Providers\ChainsServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;
use Modules\EmailScan\Providers\EmailScanServiceProvider;
use Modules\Import\Providers\ImportServiceProvider;
use Modules\Ingestion\Providers\IngestionServiceProvider;
use Modules\Ledger\Providers\LedgerServiceProvider;
use Modules\Receipts\Providers\ReceiptsServiceProvider;
use Modules\Recurring\Providers\RecurringServiceProvider;
use Modules\Transfers\Providers\TransfersServiceProvider;

return [
    HorizonServiceProvider::class,
    CoreServiceProvider::class,
    LedgerServiceProvider::class,
    IngestionServiceProvider::class,
    ImportServiceProvider::class,
    CategorizationServiceProvider::class,
    ChainsServiceProvider::class,
    EmailScanServiceProvider::class,
    TransfersServiceProvider::class,
    ReceiptsServiceProvider::class,
    RecurringServiceProvider::class,
];
