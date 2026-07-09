<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Categorization\Internal\Http\Livewire\RulesPage;
use Modules\Categorization\Internal\Jobs\ReapplyRulesJob;
use Modules\Categorization\Internal\Services\RuleApplier;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;
use Psr\Log\LoggerInterface;

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

/*
 * 14.1-07 (CR-04) — ReapplyRulesJob must decrypt counterparty_name/
 * description before RuleEngine::match() so substring conditions fire
 * against plaintext, not the ciphertext the two columns carry at rest
 * once encryption is enabled. Rows below are inserted with GENUINELY
 * encrypted values (via SensitiveColumnCodec::encryptValue, mirroring
 * what RecordTransactions writes at import time) — not plaintext — so
 * a still-broken predicate would fail this test exactly as it would
 * fail against real encrypted history (Pitfall 2).
 */

it('decrypts counterparty_name/description before matching so a rule fires against an encrypted user\'s stored ciphertext (CR-04)', function (): void {
    $user = rrjeUser();
    $session = $this->enablesEncryptionForUser($user);
    $this->actingAs($user);

    $account = rrjeAccount($user);
    $run = rrjeImportRun($user);

    $category = Category::query()->create([
        'user_id' => null,
        'name' => 'RRJE Groceries',
        'slug' => 'rrje-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $rule = CategorizationRule::query()->create([
        'user_id' => $user->id,
        'priority' => 0,
        'active' => true,
        'combinator' => 'all',
        'notes' => null,
        'hits_count' => 0,
    ]);
    $rule->conditions()->create([
        'field' => 'description',
        'op' => 'contains',
        'value_type' => 'string',
        'value' => 'weekly groceries',
        'value2' => null,
    ]);
    $rule->actions()->create(['position' => 0, 'type' => 'category', 'payload' => ['category_id' => $category->id]]);

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    $encryptedDescription = $codec->encryptValue('transactions', 'description', 'Albert Heijn weekly groceries', $user->id, $session);
    $encryptedCounterparty = $codec->encryptValue('transactions', 'counterparty_name', 'Albert Heijn', $user->id, $session);
    expect($encryptedDescription)->not->toBe('Albert Heijn weekly groceries');

    $tx = Transaction::query()->create([
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
        'counterparty_name' => $encryptedCounterparty,
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'description' => $encryptedDescription,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('rrje-cr04', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    Livewire::test(RulesPage::class)->call('triggerReapply');

    $tx->refresh();
    expect($tx->category_id)->toBe($category->id);

    $progress = $this->app->make(Repository::class)->get(ReapplyRulesJob::progressCacheKey($user->id));
    expect($progress['status'] ?? null)->toBe('done');
    expect((int) ($progress['transactions_updated'] ?? 0))->toBeGreaterThanOrEqual(1);
})->group('ReapplyRulesJobEncryption');

it('logs a warning and leaves ciphertext unmatched when the KEK is unavailable (defensive daemon-origin guard)', function (): void {
    $user = rrjeUser();
    $session = $this->enablesEncryptionForUser($user);

    $account = rrjeAccount($user);
    $run = rrjeImportRun($user);

    $category = Category::query()->create([
        'user_id' => null,
        'name' => 'RRJE Nokek',
        'slug' => 'rrje-nokek-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $rule = CategorizationRule::query()->create([
        'user_id' => $user->id,
        'priority' => 0,
        'active' => true,
        'combinator' => 'all',
        'notes' => null,
        'hits_count' => 0,
    ]);
    $rule->conditions()->create([
        'field' => 'counterparty',
        'op' => 'equals',
        'value_type' => 'string',
        'value' => 'Albert Heijn',
        'value2' => null,
    ]);
    $rule->actions()->create(['position' => 0, 'type' => 'category', 'payload' => ['category_id' => $category->id]]);

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    $encryptedCounterparty = $codec->encryptValue('transactions', 'counterparty_name', 'Albert Heijn', $user->id, $session);

    $tx = Transaction::query()->create([
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
        'counterparty_name' => $encryptedCounterparty,
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('rrje-nokek', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    // Simulate a locked/KEK-less run: withhold the session's data key
    // AFTER the fixture ciphertext was written but BEFORE the job runs.
    $this->app->make(AppLockKeyService::class)->withhold($session);

    /** @var ReapplyRulesJob $job */
    $job = app(ReapplyRulesJob::class, ['userId' => $user->id]);
    $job->handle(
        app(RuleEngine::class),
        app(RuleApplier::class),
        app(TransactionStatusQuery::class),
        app(DatabaseManager::class),
        app(Repository::class),
        app(Clock::class),
        app(LoggerInterface::class),
        $codec,
        app(Session::class),
        app(AppLockKeyService::class),
    );

    $tx->refresh();
    expect($tx->category_id)->toBeNull();
})->group('ReapplyRulesJobEncryption');
