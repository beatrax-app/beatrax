<?php

declare(strict_types=1);

use App\Providers\HorizonServiceProvider;
use App\Providers\NativeServiceProvider;
use Modules\Anomaly\Providers\AnomalyServiceProvider;
use Modules\Auth\Providers\AuthServiceProvider;
use Modules\Budgets\Providers\BudgetsServiceProvider;
use Modules\Calendar\Providers\CalendarServiceProvider;
use Modules\CashBook\Providers\CashBookServiceProvider;
use Modules\Categorization\Providers\CategorizationServiceProvider;
use Modules\Chains\Providers\ChainsServiceProvider;
use Modules\Community\Providers\CommunityServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;
use Modules\Counterparties\Providers\CounterpartiesServiceProvider;
use Modules\DevMode\Providers\DevModeServiceProvider;
use Modules\DriftAlerts\Providers\DriftAlertsServiceProvider;
use Modules\EmailScan\Providers\EmailScanServiceProvider;
use Modules\Forecasting\Providers\ForecastingServiceProvider;
use Modules\FX\Providers\FXServiceProvider;
use Modules\Goals\Providers\GoalsServiceProvider;
use Modules\Import\Providers\ImportServiceProvider;
use Modules\Ingestion\Providers\IngestionServiceProvider;
use Modules\Ledger\Providers\LedgerServiceProvider;
use Modules\Notifications\Providers\NotificationsServiceProvider;
use Modules\Onboarding\Providers\OnboardingServiceProvider;
use Modules\Position\Providers\PositionServiceProvider;
use Modules\Pots\Providers\PotsServiceProvider;
use Modules\Receipts\Providers\ReceiptsServiceProvider;
use Modules\Recurring\Providers\RecurringServiceProvider;
use Modules\Transfers\Providers\TransfersServiceProvider;

/**
 * @link ../../.docs/features/mobile/architecture.md#what-the-provider-manifest-diverges-by
 */
return array_values(array_filter([
    class_exists(Laravel\Horizon\HorizonServiceProvider::class) ? HorizonServiceProvider::class : null,
    NativeServiceProvider::class,
    CoreServiceProvider::class,
    AuthServiceProvider::class,
    LedgerServiceProvider::class,
    IngestionServiceProvider::class,
    ImportServiceProvider::class,
    CategorizationServiceProvider::class,
    ChainsServiceProvider::class,
    EmailScanServiceProvider::class,
    TransfersServiceProvider::class,
    ReceiptsServiceProvider::class,
    RecurringServiceProvider::class,
    DriftAlertsServiceProvider::class,
    AnomalyServiceProvider::class,
    BudgetsServiceProvider::class,
    CalendarServiceProvider::class,
    GoalsServiceProvider::class,
    PotsServiceProvider::class,
    FXServiceProvider::class,
    CashBookServiceProvider::class,
    ForecastingServiceProvider::class,
    DevModeServiceProvider::class,
    OnboardingServiceProvider::class,
    CommunityServiceProvider::class,
    CounterpartiesServiceProvider::class,
    NotificationsServiceProvider::class,
    PositionServiceProvider::class,
]));
