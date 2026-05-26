<?php

declare(strict_types=1);

use Modules\Import\Internal\Detectors\Camt053StartingBalanceDetector;
use Modules\Import\Internal\Detectors\IcsPdfStartingBalanceDetector;
use Modules\Import\Internal\Detectors\Mt940StartingBalanceDetector;
use Modules\Import\Internal\Detectors\PaypalCsvStartingBalanceDetector;
use Modules\Import\Public\Contracts\DetectsStartingBalance;

/*
 * Container-tag registry inventory test for the
 * `starting-balance.detector` tag.
 *
 * Locks the registered detector count, contract conformance, and
 * the documented registration-order invariant used by the
 * aggregator's tie-break rule. Adding a new detector requires:
 *   1. Append the class FQN to ImportServiceProvider::STARTING_BALANCE_DETECTOR_FQNS
 *   2. Update the expected count + ordered class-list in this test
 * — keeping every shipping detector visible from one inventory location.
 */

it('binds exactly four DetectsStartingBalance implementations under the starting-balance.detector container tag', function (): void {
    /** @var iterable<DetectsStartingBalance> $tagged */
    $tagged = $this->app->tagged('starting-balance.detector');
    $detectors = [];
    foreach ($tagged as $detector) {
        $detectors[] = $detector;
    }

    expect($detectors)->toHaveCount(4);
})->group('phase-16.1.1');

it('binds every tagged detector to the DetectsStartingBalance contract', function (): void {
    /** @var iterable<DetectsStartingBalance> $tagged */
    $tagged = $this->app->tagged('starting-balance.detector');
    foreach ($tagged as $detector) {
        expect($detector)->toBeInstanceOf(DetectsStartingBalance::class);
    }
})->group('phase-16.1.1');

it('emits the tagged detectors in the documented registration order', function (): void {
    /** @var iterable<DetectsStartingBalance> $tagged */
    $tagged = $this->app->tagged('starting-balance.detector');
    $classes = [];
    foreach ($tagged as $detector) {
        $classes[] = $detector::class;
    }

    expect($classes)->toBe([
        Camt053StartingBalanceDetector::class,
        Mt940StartingBalanceDetector::class,
        IcsPdfStartingBalanceDetector::class,
        PaypalCsvStartingBalanceDetector::class,
    ]);
})->group('phase-16.1.1');
