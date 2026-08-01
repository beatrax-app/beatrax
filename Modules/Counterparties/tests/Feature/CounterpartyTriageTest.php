<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Models\Account;

/*
 * Feature coverage for `/counterparties/triage` — the focused
 * single-card queue. Pins the eight load-bearing behaviors documented
 * in Plan 17-06b Task 2:
 *
 *   16. Renders progress copy verbatim
 *   17. Suggestion banner with reasoning sub-line
 *   18. Y key (acceptSuggestion) links the unknown to the suggested name
 *   19. N key (rejectSuggestion) dismisses the banner
 *   20. S key (skipForNow) advances the cursor without labeling
 *   21. → key (nextItem) advances the queue
 *   22. Input carve-out: keys inside INPUT do not fire wire actions
 *   23. Empty queue: verbatim 🎉 caught-up state
 */

function cpTriageUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function cpTriageUnknown(int $userId, string $slug, ?string $iban = null): int
{
    $now = now()->toDateTimeString();

    return DB::table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'unknown',
        'slug' => $slug,
        'display_name' => $iban ?? $slug,
        'iban' => $iban,
        'merchant_name' => null,
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function cpTriageImportRun(User $user): int
{
    $now = now()->toDateTimeString();

    return DB::table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn_csv',
        'raw_file_path' => 'fixture://triage-test',
        'sha256' => str_pad((string) random_int(1, 1_000_000_000), 64, 'a', STR_PAD_LEFT),
        'uploaded_at' => $now,
        'confirmed_at' => $now,
        'inserted_count' => 0,
        'duplicate_count' => 0,
        'error_count' => 0,
        'status' => 'confirmed',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function cpTriageTx(User $user, Account $account, int $counterpartyId, int $importRunId, string $description, int $amountMinor = -1500, ?string $postedAt = null): void
{
    $postedAt = $postedAt ?? now()->toDateString();
    $bookedAt = now()->toDateTimeString();
    $valueDate = $postedAt;

    DB::table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $amountMinor < 0 ? 'expense' : 'income',
        'posted_at' => $postedAt,
        'booked_at' => $bookedAt,
        'value_date' => $valueDate,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'fx_rate_used' => null,
        'counterparty_name' => null,
        'counterparty_iban' => null,
        'counterparty_normalized' => substr($description, 0, 80),
        'normalization_version' => 1,
        'description' => $description,
        'category_id' => null,
        'source_format' => 'asn_csv',
        'import_run_id' => $importRunId,
        'source_row_index' => random_int(1, 1_000_000),
        'source_ref' => 'tx-'.uniqid(),
        'fingerprint' => str_pad((string) random_int(1, 1_000_000_000), 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'status' => 'cleared',
        'counterparty_id' => $counterpartyId,
        'payment_type' => 'unknown',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function cpTriageAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Triage fixture ASN',
        'slug' => 'triage-asn-'.uniqid(),
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.str_pad((string) random_int(1, 9999999999), 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
    ]);
}

it('Test 16: renders progress copy verbatim with seen/total/percent/minutes', function (): void {
    $user = cpTriageUser('cp-triage-progress');
    // 3 unknowns total; we'll simulate 0 seen for a clean format check.
    for ($i = 1; $i <= 3; $i++) {
        cpTriageUnknown($user->id, 'mystery-'.$i, 'NL12RABO000000000'.$i);
    }

    $component = Livewire::actingAs($user)->test(CounterpartyTriage::class);

    // Format pattern: `{seen} of {total} · {percent} % · ~{minutes} min remaining`
    // Minutes is bounded by max(1, round(remaining * 0.4)); 3 unknowns
    // → max(1, round(1.2)) = 1 min remaining.
    $component->assertSee('0 of 3 · 0 % · ~1 min remaining', escape: false);
});

it('Test 17: suggestion banner renders verbatim copy + reasoning sub-line', function (): void {
    $user = cpTriageUser('cp-triage-suggest');
    $account = cpTriageAccount($user);
    $unknownId = cpTriageUnknown($user->id, 'mystery-netflix', 'NL44RABO0123456789');

    // 3 recent transactions whose descriptions all resolve via the
    // MerchantNameResolver. Use a literal merchant name the resolver's
    // community corpus / generalised name path will accept.
    $runId = cpTriageImportRun($user);
    for ($i = 0; $i < 3; $i++) {
        cpTriageTx($user, $account, $unknownId, $runId, 'NETFLIX SUBSCRIPTION ROW '.$i);
    }

    // Seed a per-user merchant_aliases row so the MerchantNameResolver
    // (consumed by CounterpartyTriageQueue::suggestionFor) returns
    // 'Netflix' for each fixture transaction. The resolver's first
    // step is the exact (user_id, pattern) match — feed it three
    // identical patterns so all 3 of 3 transactions resolve.
    DB::table('merchant_aliases')->insert([
        'user_id' => $user->id,
        'pattern' => 'NETFLIX',
        'generalized_pattern' => 'netflix',
        'friendly_name' => 'Netflix',
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $component = Livewire::actingAs($user)->test(CounterpartyTriage::class);

    $html = (string) $component->html();
    expect($html)->toContain('Looks like');
    expect($html)->toContain('<strong>Netflix</strong>');
    expect($html)->toContain('confidence high');
    // Reasoning sub-line is rendered verbatim from the queue's output —
    // shape is `{topHits} of {total} recent transactions on this IBAN
    // resolve to {name} via the merchant name resolver.`
    expect($html)->toContain('of 3 recent transactions on this IBAN resolve to Netflix');
});

it('Test 18: acceptSuggestion promotes the unknown to a merchant counterparty', function (): void {
    $user = cpTriageUser('cp-triage-accept');
    $account = cpTriageAccount($user);
    $runId = cpTriageImportRun($user);
    $unknownId = cpTriageUnknown($user->id, 'mystery-spotify', 'NL17RABO0123456790');
    cpTriageTx($user, $account, $unknownId, $runId, 'SPOTIFY P AMSTERDAM');

    // Seed a merchant_aliases row so the resolver returns 'Spotify'
    // for the fixture transaction; accept then promotes the unknown
    // counterparty to a merchant row using the resolver's output.
    DB::table('merchant_aliases')->insert([
        'user_id' => $user->id,
        'pattern' => 'SPOTIFY P AMSTERDAM',
        'generalized_pattern' => 'spotify',
        'friendly_name' => 'Spotify',
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $component = Livewire::actingAs($user)->test(CounterpartyTriage::class);
    $component->call('acceptSuggestion');

    $cp = Counterparty::query()->where('id', $unknownId)->firstOrFail();
    expect($cp->type)->toBe('merchant');
    expect($cp->display_name)->toBe('Spotify');
    expect($cp->merchant_name)->toBe('Spotify');
});

it('Test 19: rejectSuggestion dismisses the banner (showSuggestion flips to false)', function (): void {
    $user = cpTriageUser('cp-triage-reject');
    cpTriageUnknown($user->id, 'mystery-rejected', 'NL87RABO0123456791');

    $component = Livewire::actingAs($user)->test(CounterpartyTriage::class);
    $component->assertSet('showSuggestion', true);

    $component->call('rejectSuggestion');

    $component->assertSet('showSuggestion', false);
});

it('Test 20: skipForNow advances the cursor without modifying the row', function (): void {
    $user = cpTriageUser('cp-triage-skip');
    $id1 = cpTriageUnknown($user->id, 'mystery-skip-1', 'NL60RABO0123456792');
    $id2 = cpTriageUnknown($user->id, 'mystery-skip-2', 'NL33RABO0123456793');

    $component = Livewire::actingAs($user)->test(CounterpartyTriage::class);
    $component->assertSet('currentIndex', 0);

    $component->call('skipForNow');
    $component->assertSet('currentIndex', 1);

    // The row was not modified.
    $cp1 = Counterparty::query()->where('id', $id1)->firstOrFail();
    expect($cp1->type)->toBe('unknown');
});

it('Test 21: nextItem advances the queue', function (): void {
    $user = cpTriageUser('cp-triage-next');
    cpTriageUnknown($user->id, 'mystery-next-1');
    cpTriageUnknown($user->id, 'mystery-next-2');

    $component = Livewire::actingAs($user)->test(CounterpartyTriage::class);
    $component->call('nextItem');
    $component->assertSet('currentIndex', 1);
});

it('Test 22: input carve-out — Alpine guard is wired on the root element', function (): void {
    $user = cpTriageUser('cp-triage-carveout');
    cpTriageUnknown($user->id, 'mystery-carveout');

    $component = Livewire::actingAs($user)->test(CounterpartyTriage::class);

    $html = (string) $component->html();
    // The Alpine listener gates Y/N/S/→ on !inputFocused; that gate
    // tracks INPUT/TEXTAREA focus via focusin.capture / focusout.capture.
    expect($html)->toContain('inputFocused');
    expect($html)->toContain('focusin.capture');
    expect($html)->toContain('focusout.capture');
});

it('Test 23: empty queue renders the verbatim 🎉 caught-up copy', function (): void {
    $user = cpTriageUser('cp-triage-empty');
    // No unknowns seeded.

    $component = Livewire::actingAs($user)->test(CounterpartyTriage::class);

    $component->assertSee('🎉 All caught up — every counterparty is labeled.', escape: false);
    $component->assertSee('Back to counterparties →', escape: false);
});
