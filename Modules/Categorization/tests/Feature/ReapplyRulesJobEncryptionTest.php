<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\RulesPage;
use Modules\Categorization\Internal\Jobs\ReapplyRulesJob;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

/*
 * 14.1-04 (CRYPT-01) — the "enabler" regression guard for every
 * queued-job decrypt fix in Wave 3. Proves that RulesPage's
 * `triggerReapply` REQUEST-context dispatch origin now runs
 * `ReapplyRulesJob` via `dispatchSync` — fully in-process, to
 * completion, before the Livewire call returns — rather than being
 * handed to a KEK-less `queue:work` daemon. The test runs against a
 * REAL encrypted user (via EnablesEncryptionForUser) so the assertion
 * is not vacuously true against a still-plaintext fixture: if the
 * dispatch were still queued, the progress cache key would remain
 * `status: running` (or absent) immediately after the call returns,
 * since nothing would have executed the job body yet. This plan does
 * NOT fix ReapplyRulesJob's own ciphertext text-match bug (CR-04,
 * owned by plan 07) — it only proves the dispatch mechanics.
 */

function rrjeUser(): User
{
    return User::query()->create([
        'username' => 'rrje-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function rrjeAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN rrje',
        'slug' => 'rrje-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'asn',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
}

function rrjeImportRun(User $user): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/rrje.xml',
        'sha256' => hash('sha256', 'rrje-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);
}

it('runs ReapplyRulesJob synchronously to completion, in-process, for an encrypted user (14.1-04 enabler)', function (): void {
    $user = rrjeUser();
    $this->enablesEncryptionForUser($user);
    $this->actingAs($user);

    $account = rrjeAccount($user);
    $run = rrjeImportRun($user);
    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-01',
        'booked_at' => '2026-07-01 12:00:00',
        'value_date' => '2026-07-01',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'RRJE Vendor',
        'counterparty_normalized' => 'rrje vendor',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('rrje-1', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $component = Livewire::test(RulesPage::class)
        ->call('triggerReapply');

    // dispatchSync means ReapplyRulesJob::handle() runs to completion
    // BEFORE triggerReapply() returns, and Livewire's automatic
    // post-action render() therefore already observes
    // progress.status === 'done' on the SAME round trip: render()'s
    // existing done-reconciliation branch fires immediately, flipping
    // reapplyDispatched back to false and replacing the "in-flight"
    // flash with the completion summary in one request — no wire:poll
    // tick, no separate worker, ever needed. If the dispatch were
    // still queued (pre-14.1-04 behaviour), reapplyDispatched would
    // still read true here (render() would take the "still running"
    // branch since the cache payload wouldn't exist yet).
    $component->assertSet('reapplyDispatched', false);
    expect($component->get('flashMessage'))
        ->toBe('No changes — your history already matches your rules.');

    /** @var Repository $cache */
    $cache = $this->app->make(Repository::class);
    /** @var array<string, mixed>|null $progress */
    $progress = $cache->get(ReapplyRulesJob::progressCacheKey($user->id));

    expect($progress)->not->toBeNull();
    expect($progress['status'] ?? null)->toBe('done');
    expect($progress['checked'] ?? null)->toBe(1);
})->group('ReapplyRulesJobEncryption');
