<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;

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
    for ($i = 1; $i <= 3; $i++) {
        cpTriageUnknown($user->id, 'mystery-'.$i, 'NL12RABO000000000'.$i);
    }

    $component = Livewire::actingAs($user)->test(CounterpartyTriage::class);

    // Minutes is max(1, round(remaining * 0.4)), so three unknowns round to
    // 1.2 and then clamp up to the 1-minute floor.
    $component->assertSee('0 of 3 · 0 % · ~1 min remaining', escape: false);
});

it('Test 17: suggestion banner renders verbatim copy + reasoning sub-line', function (): void {
    $user = cpTriageUser('cp-triage-suggest');
    $account = cpTriageAccount($user);
    $unknownId = cpTriageUnknown($user->id, 'mystery-netflix', 'NL44RABO0123456789');

    $runId = cpTriageImportRun($user);
    for ($i = 0; $i < 3; $i++) {
        cpTriageTx($user, $account, $unknownId, $runId, 'NETFLIX SUBSCRIPTION ROW '.$i);
    }

    // The suggestion comes from MerchantNameResolver, which needs an alias
    // row before these descriptions resolve to a name at all.
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
    expect($html)->toContain('of 3 recent transactions on this IBAN resolve to Netflix');
});

it('Test 18: acceptSuggestion promotes the unknown to a merchant counterparty', function (): void {
    $user = cpTriageUser('cp-triage-accept');
    $account = cpTriageAccount($user);
    $runId = cpTriageImportRun($user);
    $unknownId = cpTriageUnknown($user->id, 'mystery-spotify', 'NL17RABO0123456790');
    cpTriageTx($user, $account, $unknownId, $runId, 'SPOTIFY P AMSTERDAM');

    // The name the accept path promotes the row to comes from the resolver,
    // so it has to have an alias to resolve.
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
    // The Alpine listener gates Y/N/S/→ on !inputFocused, which the capture
    // handlers keep in step with INPUT/TEXTAREA focus.
    expect($html)->toContain('inputFocused');
    expect($html)->toContain('focusin.capture');
    expect($html)->toContain('focusout.capture');
});

it('Test 23: empty queue renders the verbatim 🎉 caught-up copy', function (): void {
    $user = cpTriageUser('cp-triage-empty');

    $component = Livewire::actingAs($user)->test(CounterpartyTriage::class);

    $component->assertSee('🎉 All caught up — every counterparty is labeled.', escape: false);
    $component->assertSee('Back to counterparties →', escape: false);
});

// android-19: the recent-transactions list applied abs() unconditionally, so a
// €52,60 charge and a €52,60 refund rendered identically — and /transactions
// showed the same row signed, so a reader cross-checking got two different
// numbers with no way to tell which was right. Direction is the strongest
// signal this screen has for the question it is asking about an unknown IBAN.

/** @return list<string> the rendered text of each triage-tx__amount span, in order */
function cpTriageAmountsFor(int $userId): array
{
    $html = (string) Livewire::actingAs(User::query()->findOrFail($userId))
        ->test(CounterpartyTriage::class)
        ->html();

    preg_match_all('/triage-tx__amount"[^>]*>(.*?)<\/span>/s', $html, $matches);

    return array_values(array_map(
        static fn (string $raw): string => html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        $matches[1],
    ));
}

it('android-19: renders an expense and an income of equal size differently', function (): void {
    $user = cpTriageUser('cp-triage-sign');
    $account = cpTriageAccount($user);
    $run = cpTriageImportRun($user);
    $unknown = cpTriageUnknown((int) $user->id, 'sign-unknown', 'NL10BANK0000000303');

    cpTriageTx($user, $account, $unknown, $run, 'Betaalautomaat Apotheek Zuiderhout', -2345, '2026-08-20');
    cpTriageTx($user, $account, $unknown, $run, 'Terugbetaling Apotheek Zuiderhout', 2345, '2026-08-19');

    $rendered = cpTriageAmountsFor((int) $user->id);

    expect($rendered)->toHaveCount(2);
    expect($rendered[0])->not->toBe($rendered[1]);
});

// Verbatim from the device: a Dutch reader, `€ -23,45` on /transactions and
// `€ 23,45` on triage for the same row. The Dutch rule puts the symbol before
// the sign, and Money::format() already does that — the sign just has to survive.
it('android-19: shows a Dutch reader the same signed amount the ledger shows', function (): void {
    App::setLocale('nl');

    $user = cpTriageUser('cp-triage-sign-nl');
    $account = cpTriageAccount($user);
    $run = cpTriageImportRun($user);
    $unknown = cpTriageUnknown((int) $user->id, 'sign-nl', 'NL10BANK0000000404');

    cpTriageTx($user, $account, $unknown, $run, 'Betaalautomaat Apotheek Zuiderhout', -2345, '2026-08-20');

    $rendered = cpTriageAmountsFor((int) $user->id);

    expect($rendered[0])->toBe(Money::ofMinor(-2345, Currency::Eur->value)->format());
    expect($rendered[0])->toContain('-');
    expect($rendered[0])->toContain('23,45');
});

it('android-19: renders a non-euro row in its own currency rather than as euros', function (): void {
    $user = cpTriageUser('cp-triage-currency');
    $account = cpTriageAccount($user);
    $run = cpTriageImportRun($user);
    $unknown = cpTriageUnknown((int) $user->id, 'sign-usd', 'NL10BANK0000000505');

    cpTriageTx($user, $account, $unknown, $run, 'Overseas charge', -2345, '2026-08-20');
    DB::table('transactions')->where('counterparty_id', $unknown)->update(['currency' => 'USD']);

    $rendered = cpTriageAmountsFor((int) $user->id);

    expect($rendered[0])->toBe(Money::ofMinor(-2345, 'USD')->format());
    expect($rendered[0])->not->toContain('€');
});
