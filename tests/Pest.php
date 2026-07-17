<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Contracts', 'Snapshot');

pest()->extend(TestCase::class)->in('Unit');

/*
 * Module-local tests live under Modules/<Name>/tests/. Pest's BootFiles
 * bootstrapper only auto-loads `tests/Pest.php` at the project root, so
 * the per-module Pest.php files are inert. Bind every module's Feature
 * suite to RefreshDatabase + the module's TestCase here so module tests
 * inherit a booted Laravel app + a clean database transaction.
 */
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
        'Modules/Receipts' => Modules\Receipts\Tests\TestCase::class,
        'Modules/Recurring' => Modules\Recurring\Tests\TestCase::class,
        'Modules/Reports' => Modules\Reports\Tests\TestCase::class,
        'Modules/Search' => Modules\Search\Tests\TestCase::class,
        'Modules/Sync' => Modules\Sync\Tests\TestCase::class,
        'Modules/Tax' => Modules\Tax\Tests\TestCase::class,
        'Modules/Transfers' => Modules\Transfers\Tests\TestCase::class,
    ] as $module => $testCase
) {
    pest()->extend($testCase)
        ->use(RefreshDatabase::class)
        ->in(__DIR__.'/../'.$module.'/tests/Feature');

    pest()->extend($testCase)->in(__DIR__.'/../'.$module.'/tests/Unit');

    // Integration directory: present where a module has tests that
    // exec external binaries (Modules/Ingestion/tests/Integration/
    // smokes the real `pdftotext` binary). Tagged ->group('integration')
    // so CI hosts without the binary can --exclude-group=integration.
    pest()->extend($testCase)->in(__DIR__.'/../'.$module.'/tests/Integration');

    // Contracts directory: per-module contract suites under
    // Modules/<Name>/tests/Contracts/. Wave 2 (Modules/Chains) is the
    // first per-module Contracts subtree and it needs a booted Laravel
    // app + RefreshDatabase to drive Eloquent models. The phpunit.xml
    // already exposes the directory via the named ChainsContracts
    // testsuite; this binds the framework bootstrap to the discovered
    // files so the module-local Pest.php remains the inert convention.
    pest()->extend($testCase)
        ->use(RefreshDatabase::class)
        ->in(__DIR__.'/../'.$module.'/tests/Contracts');

    // Snapshot directory: per-module snapshot suites that compare
    // serialised output (rendered HTML, generated YAML) against a
    // committed fixture via spatie/pest-plugin-snapshots. Bound to
    // RefreshDatabase + the module's TestCase so the snapshot tests
    // can seed fixtures through Eloquent before serialising.
    pest()->extend($testCase)
        ->use(RefreshDatabase::class)
        ->in(__DIR__.'/../'.$module.'/tests/Snapshot');

    // Arch directory: per-module arch-invariant suites. Pest's `arch()`
    // plugin walks the class graph regardless of where the calling
    // file lives, so the satellite assertions sit next to the rest of
    // the module's test tree for fast `vendor/bin/pest Modules/<Name>/`
    // feedback. The project-wide tests/Contracts/BoundaryArchTest.php
    // remains the authoritative gate run as part of `composer test`.
    // No TestCase binding is needed because arch() does not boot the
    // Laravel container, but the loop entry keeps the discovery shape
    // uniform with Feature / Unit / Integration / Contracts / Snapshot.
    pest()->extend($testCase)
        ->in(__DIR__.'/../'.$module.'/tests/Arch');
}

/**
 * Writes the given MT940 body to a fresh tempnam-keyed `.sta` file and
 * registers a shutdown cleanup so the temp file is removed when the PHP
 * process exits. Shared by the MT940 lexer / Tag61 / Tag86 / adapter /
 * import tests.
 */
function writeMt940Temp(string $body): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'mt940-').'.sta';
    file_put_contents($tmp, $body);
    register_shutdown_function(static function () use ($tmp): void {
        @unlink($tmp);
    });

    return $tmp;
}

/**
 * Fixture helper for Community feature tests. Returns a freshly-
 * persisted User with sensible defaults so per-test setup blocks stay
 * focused on the assertion under test.
 */
function makeCommunityTestUser(string $username = 'community-user'): User
{
    return User::create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}
