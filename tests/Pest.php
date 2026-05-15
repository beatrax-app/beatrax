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

/*
 * Phase-2 group convention.
 *
 * There is NO global registration here — the `phase-2` group is created
 * implicitly by tests chaining ->group('phase-2') on each it(...) call
 * (see e.g. FingerprintComposerV3Test, AsnCamt053AdapterTest, etc.).
 *
 * The focused dev loop is:
 *     vendor/bin/pest --group=phase-2 --bail
 *
 * If a Phase 2 test forgets the ->group('phase-2') chain, the focused
 * run will silently skip it — review individual test files when in
 * doubt, not this bootstrap.
 */

/*
 * Phase-3 group convention.
 *
 * Same shape as Phase 2: no global registration here — the `phase-3`
 * group is created implicitly by tests chaining ->group('phase-3') on
 * each it(...) call (ICS PDF adapter, ICS PDF extractor, ICS amount /
 * date parsers, ICS import wire-level, Settings page round-trip, the
 * currency-view toggle / dashboard / detail surfaces in Ledger, plus
 * the repo-wide AnonymisedFixtureSweep guard).
 *
 * The focused dev loop is:
 *     vendor/bin/pest --group=phase-3 --bail
 *
 * If a Phase 3 test forgets the ->group('phase-3') chain, the focused
 * run will silently skip it — review individual test files when in
 * doubt, not this bootstrap.
 */
