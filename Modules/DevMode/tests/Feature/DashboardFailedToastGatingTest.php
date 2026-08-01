<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

/*
 * Dashboard failed-chain toast gating + retarget.
 *
 * The dashboard's "Chain resolution failed" toast is gated on
 * is_developer AND points at the canonical
 * route('dev.queue.tab', ['tab' => 'failed']) URL.
 *
 * Non-developers see NO queue-failure messaging at all — their
 * channel is the existing SystemAlertsBanner (the system-wide
 * banner that surfaces operational failures through a separate
 * pipeline).
 *
 * The toast copy is locked:
 *   Body:   "Chain resolution failed."
 *   Action: "Open Queue Inspector"
 */

function dashFailedToastUser(string $username, bool $isDeveloper): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);

    // Bypass the first-run EnsureDatabaseReady redirect by seeding
    // one ImportRun + Account + Transaction for the user. Mirrors
    // the fixture shape Modules/Chains/tests/Feature/FailedChainResolutionToastTest.php
    // already uses.
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/dft-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => str_repeat('e', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'dft asn '.$username,
        'slug' => 'dft-asn-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL57DFT'.substr(bin2hex(random_bytes(2)), 0, 4),
        'default_currency' => 'EUR',
    ]);
    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'value_date' => '2026-05-10',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Seed',
        'counterparty_normalized' => 'seed',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('dft-'.bin2hex(random_bytes(8)), 64, 'd', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);

    return $user;
}

/**
 * Seed a failed chain-resolution run for the given user — the
 * Dashboard's `refreshFailedChainResolution()` reads the
 * `chain_resolution_runs` table filtered by `user_id` AND
 * `status = 'failed'`.
 */
function dashFailedToastSeedRun(int $userId): void
{
    DB::table('chain_resolution_runs')->insert([
        'user_id' => $userId,
        'status' => 'failed',
        'started_at' => CarbonImmutable::now()->subMinutes(2),
        'completed_at' => CarbonImmutable::now()->subMinutes(1),
        'last_error' => 'simulated chain-resolution failure',
        'linked_count' => 0,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);
}

it('renders the "Open Queue Inspector" toast pointing at route("dev.queue.tab", ["tab" => "failed"]) for a developer with a failed chain-resolution run', function (): void {
    dashFailedToastUser('dft-seed-for-dev', true);
    $user = dashFailedToastUser('dft-developer', true);
    dashFailedToastSeedRun($user->id);

    $response = $this->actingAs($user)->get('/');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('Chain resolution failed.');
    expect($html)->toContain('Open Queue Inspector');

    // The dashboard MUST build the URL via
    // route('dev.queue.tab', ['tab' => 'failed']) — assert the
    // rendered href EQUALS the URL the route() helper resolves,
    // NOT a hardcoded `/dev/queue/failed` literal (which would
    // point at a non-existent dev.queue.failed route alias).
    $expectedHref = route('dev.queue.tab', ['tab' => 'failed']);
    expect($html)->toContain('href="'.$expectedHref.'"');
});

it('does NOT render the "Open Queue Inspector" toast for a non-developer EVEN with a failed chain-resolution run (non-devs route through SystemAlertsBanner)', function (): void {
    dashFailedToastUser('dft-seed-for-nondev', true);
    $user = dashFailedToastUser('dft-non-developer', false);
    dashFailedToastSeedRun($user->id);

    $response = $this->actingAs($user)->get('/');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->not->toContain('Open Queue Inspector');
    expect($html)->not->toContain('/dev/queue');
});

it('does NOT render the toast for a developer WITHOUT a failed chain-resolution run (the trigger is the failed row, not the developer flag)', function (): void {
    dashFailedToastUser('dft-seed-for-clean', true);
    $user = dashFailedToastUser('dft-developer-clean', true);
    // No seed → no failed row.

    $response = $this->actingAs($user)->get('/');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->not->toContain('Chain resolution failed.');
    expect($html)->not->toContain('Open Queue Inspector');
});

it('the previous Open Horizon copy is gone — the retarget is final, not additive', function (): void {
    dashFailedToastUser('dft-seed-for-horizon-removal', true);
    $user = dashFailedToastUser('dft-no-horizon-copy', true);
    dashFailedToastSeedRun($user->id);

    $response = $this->actingAs($user)->get('/');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->not->toContain('Open Horizon');
    expect($html)->not->toContain('/horizon/failed');
});
