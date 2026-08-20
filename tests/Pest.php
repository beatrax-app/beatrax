<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Contracts', 'Snapshot');

pest()->extend(TestCase::class)->in('Unit');

// Pest's BootFiles bootstrapper only auto-loads the project-root tests/Pest.php,
// so every Modules/<Name>/tests/Pest.php is inert. Each module's suites are
// bound to RefreshDatabase and the module's own TestCase from here.
foreach (
    [
        'Modules/Anomaly' => Modules\Anomaly\Tests\TestCase::class,
        'Modules/Auth' => Modules\Auth\Tests\TestCase::class,
        'Modules/Budgets' => Modules\Budgets\Tests\TestCase::class,
        'Modules/Calendar' => Modules\Calendar\Tests\TestCase::class,
        'Modules/Goals' => Modules\Goals\Tests\TestCase::class,
        'Modules/Pots' => Modules\Pots\Tests\TestCase::class,
        'Modules/CashBook' => Modules\CashBook\Tests\TestCase::class,
        'Modules/Categorization' => Modules\Categorization\Tests\TestCase::class,
        'Modules/Chains' => Modules\Chains\Tests\TestCase::class,
        'Modules/Community' => Modules\Community\Tests\TestCase::class,
        'Modules/Core' => Modules\Core\Tests\TestCase::class,
        'Modules/Counterparties' => Modules\Counterparties\Tests\TestCase::class,
        'Modules/Desktop' => Modules\Desktop\Tests\TestCase::class,
        'Modules/DevMode' => Modules\DevMode\Tests\TestCase::class,
        'Modules/DriftAlerts' => Modules\DriftAlerts\Tests\TestCase::class,
        'Modules/EmailScan' => Modules\EmailScan\Tests\TestCase::class,
        'Modules/Forecasting' => Modules\Forecasting\Tests\TestCase::class,
        'Modules/FX' => Modules\FX\Tests\TestCase::class,
        'Modules/Import' => Modules\Import\Tests\TestCase::class,
        'Modules/Ingestion' => Modules\Ingestion\Tests\TestCase::class,
        'Modules/Ledger' => Modules\Ledger\Tests\TestCase::class,
        'Modules/Migration' => Modules\Migration\Tests\TestCase::class,
        'Modules/Mobile' => Modules\Mobile\Tests\TestCase::class,
        'Modules/Notifications' => Modules\Notifications\Tests\TestCase::class,
        'Modules/Onboarding' => Modules\Onboarding\Tests\TestCase::class,
        'Modules/OpenBanking' => Modules\OpenBanking\Tests\TestCase::class,
        'Modules/Position' => Modules\Position\Tests\TestCase::class,
        'Modules/Receipts' => Modules\Receipts\Tests\TestCase::class,
        'Modules/Recurring' => Modules\Recurring\Tests\TestCase::class,
        'Modules/Reports' => Modules\Reports\Tests\TestCase::class,
        'Modules/Search' => Modules\Search\Tests\TestCase::class,
        'Modules/Sync' => Modules\Sync\Tests\TestCase::class,
        'Modules/Tax' => Modules\Tax\Tests\TestCase::class,
        'Modules/Transfers' => Modules\Transfers\Tests\TestCase::class,
    ] as $module => $testCase
) {
    // The mobile Composer root maps two module test namespaces, not all
    // thirty-four, and Pest resolves the class the moment it binds one — without
    // this guard the Mobile suite dies on TestCaseClassOrTraitNotFound. It hid on
    // macOS, whose case-insensitive path resolution finds tests/ where Linux cannot.
    if (! class_exists($testCase)) {
        continue;
    }

    pest()->extend($testCase)
        ->use(RefreshDatabase::class)
        ->in(__DIR__.'/../'.$module.'/tests/Feature');

    pest()->extend($testCase)->in(__DIR__.'/../'.$module.'/tests/Unit');

    // Integration suites exec external binaries — Modules/Ingestion smokes the
    // real pdftotext.
    pest()->extend($testCase)->in(__DIR__.'/../'.$module.'/tests/Integration');

    pest()->extend($testCase)
        ->use(RefreshDatabase::class)
        ->in(__DIR__.'/../'.$module.'/tests/Contracts');

    pest()->extend($testCase)
        ->use(RefreshDatabase::class)
        ->in(__DIR__.'/../'.$module.'/tests/Snapshot');

    // arch() walks the class graph regardless of where the calling file lives and
    // never boots the container, so this binding needs no RefreshDatabase.
    pest()->extend($testCase)
        ->in(__DIR__.'/../'.$module.'/tests/Arch');
}

function writeMt940Temp(string $body): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'mt940-').'.sta';
    file_put_contents($tmp, $body);
    register_shutdown_function(static function () use ($tmp): void {
        @unlink($tmp);
    });

    return $tmp;
}

function makeCommunityTestUser(string $username = 'community-user'): User
{
    return User::create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}
