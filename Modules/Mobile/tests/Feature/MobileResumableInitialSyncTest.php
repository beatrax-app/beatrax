<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

/*
 * R5 / MOBILE-01 — Wave-0 RED contract test (D-03/D-04 resumable initial
 * sync). Turns GREEN in Plan 08 (15-08), which flesh this file out to
 * seed a partial mobile_sync_progress cursor, re-instantiate
 * InitialSyncPuller (simulating an app-kill mid-pull), and assert it
 * resumes from the durable HLC watermark with no duplication.
 *
 * Behavior pinned: a partial `mobile_sync_progress` cursor + a
 * re-instantiated `InitialSyncPuller` (simulated app-kill) resumes from
 * the watermark, applies no duplicate rows, and eventually reaches
 * records_applied === records_expected with phase = 'complete'.
 *
 * RED reason: `Modules\Mobile\Internal\Sync\InitialSyncPuller` (Plan 08's
 * resumable-pull orchestrator, per 15-08-PLAN.md) does not exist yet —
 * this is a clean, collectible FAILING assertion (not a class-not-found
 * error), so the suite still collects and reports 4 failing, not errored.
 *
 * The durable `mobile_sync_progress` table itself already exists (Task 2
 * of this plan) — seeding a partial cursor here proves the table shape is
 * usable ahead of Plan 08 wiring the real puller against it.
 */
it('resumes an initial sync from a durable mobile_sync_progress cursor with no duplication after a simulated app-kill', function (): void {
    $user = User::query()->create([
        'username' => 'mobile-resume-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // Seed a partial cursor as if a prior pull was killed mid-batch.
    app(DatabaseManager::class)->connection()->table('mobile_sync_progress')->insert([
        'user_id' => $user->id,
        'peer_device_id' => 'desktop-fixture-device',
        'records_expected' => 100,
        'records_applied' => 40,
        'last_hlc_l' => 1_700_000_000_000,
        'last_hlc_c' => 3,
        'phase' => 'pulling',
        'created_at' => '2026-07-10 00:00:00',
        'updated_at' => '2026-07-10 00:05:00',
    ]);

    $pullerClass = 'Modules\Mobile\Internal\Sync\InitialSyncPuller';

    expect(class_exists($pullerClass))->toBeTrue(
        'InitialSyncPuller does not exist yet — the resumable initial-sync pull lands in Plan 08 (15-08). '
        .'Once it ships, flesh this test out into the resume-from-watermark/no-duplication/eventual-parity '
        .'assertion described in the Task 3 behavior docblock above (this seeded partial cursor is the fixture).',
    );
});
