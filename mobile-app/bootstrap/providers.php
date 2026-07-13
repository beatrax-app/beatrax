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
use Modules\Onboarding\Providers\OnboardingServiceProvider;
use Modules\Pots\Providers\PotsServiceProvider;
use Modules\Receipts\Providers\ReceiptsServiceProvider;
use Modules\Recurring\Providers\RecurringServiceProvider;
use Modules\Transfers\Providers\TransfersServiceProvider;

/*
 * Mobile-app provider manifest.
 *
 * Copied verbatim from the desktop root's `bootstrap/providers.php` with ONE
 * removal: `Modules\Desktop\Providers\DesktopServiceProvider` is NOT registered
 * here. The Desktop module is not an nwidart-discovered module (it has no
 * `module.json`); it is wired ONLY through this hardcoded manifest. Dropping it
 * here is therefore the authoritative lever that keeps `Modules\Desktop` — and
 * its hard dependency on the `nativephp/desktop` Composer package (absent from
 * this root's `vendor/`) — out of the mobile shell entirely.
 *
 * The Mobile module itself is NOT listed here: it ships a `module.json` and is
 * loaded by nwidart's FileActivator from this root's `modules_statuses.json`
 * (Mobile => true), exactly as under the desktop root. The other nwidart-only
 * modules (Sync, Reports, Search, Tax, Migration, Counterparties) likewise load
 * from `modules_statuses.json`.
 *
 * HorizonServiceProvider is guarded: a `--no-dev` mobile bundle carries no
 * laravel/horizon package, so the class_exists() guard drops the entry.
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
]));
