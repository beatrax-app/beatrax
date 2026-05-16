<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Contracts');

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
        'Modules/Categorization' => Modules\Categorization\Tests\TestCase::class,
        'Modules/Chains' => Modules\Chains\Tests\TestCase::class,
        'Modules/Core' => Modules\Core\Tests\TestCase::class,
        'Modules/EmailScan' => Modules\EmailScan\Tests\TestCase::class,
        'Modules/Import' => Modules\Import\Tests\TestCase::class,
        'Modules/Ingestion' => Modules\Ingestion\Tests\TestCase::class,
        'Modules/Ledger' => Modules\Ledger\Tests\TestCase::class,
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
