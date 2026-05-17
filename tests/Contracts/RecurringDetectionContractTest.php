<?php

declare(strict_types=1);

/**
 * Recurring detection end-to-end contract.
 *
 * Locks the full Recurring detector spec by sweeping the Wave 0
 * fixture corpus (synthesised + one anonymised real export) and
 * asserting the resulting series set, cadences, directions, and
 * metrics. The corpus lives under
 * `Modules/Recurring/tests/fixtures/synthesised/*.php`
 * and `Modules/Recurring/tests/fixtures/real/*.php`.
 *
 * This file is the scaffold-only placeholder; the load-bearing
 * assertion helpers (`assertSeriesSet`, `assertCadences`,
 * `assertMetrics`) land alongside the detector implementations in
 * later waves.
 */
it('runs the recurring detection sweep end-to-end against the Wave 0 fixture corpus')
    ->skip('Populated in Wave 2/3 — fixtures land in Plan 01; detectors land in Plans 03/04.');
