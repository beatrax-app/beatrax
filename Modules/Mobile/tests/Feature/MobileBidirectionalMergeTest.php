<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

/*
 * MOBILE-01 — Wave-0 RED contract test. Turns GREEN in Plan 05 (15-05),
 * which flesh this file out into a two-SQLite-instance harness (mirroring
 * the Phase 11 op-log merge tests) proving phone<->desktop convergence
 * through the UNCHANGED OpLogReplayer/MergeRulesRegistry (SPEC "consumed
 * as-is").
 *
 * Behavior pinned: a phone-originated and a desktop-originated op on the
 * SAME (table, pk, field) converge to an identical value on both peers,
 * regardless of forward-vs-reverse replay order (order-independence).
 *
 * RED reason: `Modules\Mobile\Internal\Sync\MobileSyncTriggerService`
 * (Plan 05's bounded-dial-out orchestrator, per 15-05-PLAN.md) does not
 * exist yet — this is a clean, collectible FAILING assertion (not a
 * class-not-found error), so the suite still collects and reports 4
 * failing, not errored.
 */
it('converges a phone-originated and a desktop-originated op on the same (table,pk,field) identically on both peers', function (): void {
    $user = User::query()->create([
        'username' => 'mobile-merge-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $triggerServiceClass = 'Modules\Mobile\Internal\Sync\MobileSyncTriggerService';

    expect(class_exists($triggerServiceClass))->toBeTrue(
        'MobileSyncTriggerService does not exist yet — bounded LAN/relay dial-out orchestration lands in '
        .'Plan 05 (15-05). Once it ships, flesh this test out into the two-SQLite-instance convergence '
        .'harness described in the Task 3 behavior docblock above (user id '.$user->id.' seeds the fixture).',
    );
});
