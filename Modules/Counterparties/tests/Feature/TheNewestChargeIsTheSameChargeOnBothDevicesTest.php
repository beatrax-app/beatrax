<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Queries\CounterpartyIndexQuery;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Counterparties\Public\Queries\CounterpartyTriageQueue;
use Modules\Ledger\Models\Account;

// Five reads here used to end on `id`, and `counterparties.id` and
// `transactions.id` are both per-device autoincrements: the same logical row is
// a different number on the paired device. Two decide what the screen says, one
// decides which rows the reader is offered at all, and the last two are capped
// lists whose CONTENTS the tie picks — the evidence for a triage decision, and
// the profile's recent activity.

function agreedNewestUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function agreedNewestAccount(User $user, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Agreed newest '.$iban,
        'slug' => 'agreed-newest-'.uniqid(),
        'kind' => 'bank',
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function agreedNewestRun(User $user): int
{
    $now = now()->toDateTimeString();

    return DB::table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn_csv',
        'raw_file_path' => 'fixture://agreed-newest',
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

function agreedNewestCounterparty(int $userId, string $slug, string $updatedAt): int
{
    return DB::table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'unknown',
        'slug' => $slug,
        'display_name' => $slug,
        'iban' => null,
        'merchant_name' => null,
        'metadata' => null,
        'created_at' => $updatedAt,
        'updated_at' => $updatedAt,
    ]);
}

// Every column the agreed order reads is fixed but occurrence_ordinal, which
// is what parts two charges a statement books identically. description is NOT
// one of them — it is what the read-out shows, so it is what has to differ.
function agreedNewestCharge(
    User $user,
    Account $account,
    int $counterpartyId,
    int $runId,
    int $ordinal,
    string $description,
): int {
    $now = now()->toDateTimeString();

    return DB::table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500,
        'settled_currency' => 'EUR',
        'counterparty_name' => null,
        'counterparty_iban' => null,
        'counterparty_normalized' => 'agreed-newest-payee',
        'normalization_version' => 1,
        'description' => $description,
        'source_format' => 'asn_csv',
        'import_run_id' => $runId,
        'source_row_index' => $ordinal,
        'source_ref' => 'agreed-'.uniqid(),
        'occurrence_ordinal' => $ordinal,
        'fingerprint' => str_pad((string) random_int(1, 1_000_000_000), 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'status' => 'cleared',
        'counterparty_id' => $counterpartyId,
        'payment_type' => 'unknown',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('shows the charge the statement booked last, not the charge this device numbered last', function (): void {
    $user = agreedNewestUser('agreed-newest-index');
    $account = agreedNewestAccount($user, 'NL57ASNB0123456789');
    $runId = agreedNewestRun($user);
    $cpId = agreedNewestCounterparty($user->id, 'agreed-newest-shop', now()->toDateTimeString());

    // Two charges the bank booked on one day, parted only by the ordinal the
    // statement counted them in. The LATER one is inserted first, so it holds
    // the LOWER id: the two rules answer with different rows, and the row is
    // the description on the card.
    $later = agreedNewestCharge($user, $account, $cpId, $runId, 1, 'LATE CHARGE ON THE FILE');
    $earlier = agreedNewestCharge($user, $account, $cpId, $runId, 0, 'EARLY CHARGE ON THE FILE');

    expect($earlier)->toBeGreaterThan($later, 'the fixture no longer inverts id order against ordinal order');

    /** @var CounterpartyIndexQuery $query */
    $query = app(CounterpartyIndexQuery::class);
    $row = $query->forUser($user)->firstOrFail();

    expect($row->recentLine)->toContain('LATE CHARGE ON THE FILE');
});

it('offers the same unknowns past the cap however this device numbered them', function (): void {
    $user = agreedNewestUser('agreed-newest-queue');
    $sharedUpdatedAt = '2026-09-10 21:10:04';

    // The live install has 54 counterparties carrying two distinct updated_at
    // values, 49 of them sharing one — so past the cap the second term decides
    // membership on its own. Inserted slug-ascending, so the row the agreed
    // rule drops is the one the id rule keeps, and the other way round.
    for ($i = 0; $i <= 200; $i++) {
        agreedNewestCounterparty($user->id, sprintf('agreed-cp-%03d', $i), $sharedUpdatedAt);
    }

    /** @var CounterpartyTriageQueue $queue */
    $queue = app(CounterpartyTriageQueue::class);
    $slugs = array_map(static fn (Counterparty $row): string => (string) $row->slug, $queue->forUser($user));

    expect($slugs)->toHaveCount(200)
        ->and($slugs)->toContain('agreed-cp-000')
        ->and($slugs)->not->toContain('agreed-cp-200');
});

it('tallies the twenty descriptions the statement puts last, not the twenty this device numbered last', function (): void {
    $user = agreedNewestUser('agreed-newest-suggestion');
    $account = agreedNewestAccount($user, 'NL91ASNB0417164300');
    $runId = agreedNewestRun($user);
    $cpId = agreedNewestCounterparty($user->id, 'agreed-newest-mystery', now()->toDateTimeString());

    foreach (['ALPHA STORE' => 'Alpha', 'BETA STORE' => 'Beta'] as $pattern => $friendly) {
        DB::table('merchant_aliases')->insert([
            'user_id' => $user->id,
            'pattern' => $pattern,
            'generalized_pattern' => strtolower($friendly),
            'friendly_name' => $friendly,
            'merged_from' => null,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    // Twenty-one charges, inserted ordinal-DESCENDING so the id order is the
    // reverse of the statement's. The cap keeps twenty, and the one it drops
    // is a different charge under each rule — which is the whole tally, since
    // ordinal 20 is an ALPHA and ordinal 0 a BETA.
    $descriptions = [20 => 'ALPHA STORE'];
    for ($ordinal = 19; $ordinal >= 1; $ordinal--) {
        $descriptions[$ordinal] = $ordinal >= 10 ? 'ALPHA STORE' : 'BETA STORE';
    }
    $descriptions[0] = 'BETA STORE';

    foreach ($descriptions as $ordinal => $description) {
        agreedNewestCharge($user, $account, $cpId, $runId, $ordinal, $description);
    }

    /** @var CounterpartyTriageQueue $queue */
    $queue = app(CounterpartyTriageQueue::class);
    $unknown = Counterparty::query()->findOrFail($cpId);

    expect($queue->suggestionFor($unknown)?->suggestedCounterpartyName)->toBe('Alpha');
});

it('lists the profile\'s recent activity in the order the statement booked it', function (): void {
    $user = agreedNewestUser('agreed-newest-profile');
    $account = agreedNewestAccount($user, 'NL02ABNA0123456789');
    $runId = agreedNewestRun($user);
    $cpId = agreedNewestCounterparty($user->id, 'agreed-newest-profile-shop', now()->toDateTimeString());

    // The later charge is inserted first, so it holds the LOWER id: a limit of
    // one keeps a different row under each rule.
    $later = agreedNewestCharge($user, $account, $cpId, $runId, 1, 'LATE CHARGE ON THE FILE');
    $earlier = agreedNewestCharge($user, $account, $cpId, $runId, 0, 'EARLY CHARGE ON THE FILE');

    expect($earlier)->toBeGreaterThan($later, 'the fixture no longer inverts id order against ordinal order');

    /** @var CounterpartyProfileQuery $query */
    $query = app(CounterpartyProfileQuery::class);
    $cp = Counterparty::query()->findOrFail($cpId);

    $descriptions = $query->recentActivity($cp, 1)
        ->map(static fn (stdClass $row): string => (string) $row->description)
        ->all();

    expect($descriptions)->toBe(['LATE CHARGE ON THE FILE']);
});

it('puts the same five charges in front of the reader the triage decision is made on', function (): void {
    $user = agreedNewestUser('agreed-newest-evidence');
    $account = agreedNewestAccount($user, 'NL69INGB0123456789');
    $runId = agreedNewestRun($user);
    $cpId = agreedNewestCounterparty($user->id, 'agreed-newest-evidence-shop', now()->toDateTimeString());

    // Six charges the bank booked on one day, inserted ordinal-DESCENDING so
    // the id order runs the other way to the statement's. Five are kept, and
    // the one dropped is a different charge under each rule.
    for ($ordinal = 5; $ordinal >= 0; $ordinal--) {
        agreedNewestCharge($user, $account, $cpId, $runId, $ordinal, 'CHARGE ORDINAL '.$ordinal);
    }

    $shown = Livewire::actingAs($user)->test(CounterpartyTriage::class)->viewData('recentTransactions');

    expect(array_map(static fn (stdClass $row): string => (string) $row->description, $shown))->toBe([
        'CHARGE ORDINAL 5',
        'CHARGE ORDINAL 4',
        'CHARGE ORDINAL 3',
        'CHARGE ORDINAL 2',
        'CHARGE ORDINAL 1',
    ]);
});
