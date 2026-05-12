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
        'Modules/Core' => Modules\Core\Tests\TestCase::class,
        'Modules/Import' => Modules\Import\Tests\TestCase::class,
        'Modules/Ingestion' => Modules\Ingestion\Tests\TestCase::class,
        'Modules/Ledger' => Modules\Ledger\Tests\TestCase::class,
    ] as $module => $testCase
) {
    pest()->extend($testCase)
        ->use(RefreshDatabase::class)
        ->in(__DIR__.'/../'.$module.'/tests/Feature');

    pest()->extend($testCase)->in(__DIR__.'/../'.$module.'/tests/Unit');
}
