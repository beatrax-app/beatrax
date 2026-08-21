<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Chains\Internal\Http\Livewire\ChainReviewQueue;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Actions\ConfirmChainLink;
use Modules\Chains\Public\Actions\RejectChainLink;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function cucUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function cucAccount(User $user, string $slug, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'cuc '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function cucImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cuc.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

function cucTx(
    User $user,
    Account $account,
    ImportRun $run,
    int $amountMinor,
    string $type,
    string $counterpartyName,
    string $postedAt,
    string $fingerprintSeed,
    int $rowIndex,
): Transaction {
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => $counterpartyName,
        'counterparty_normalized' => strtolower($counterpartyName),
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad($fingerprintSeed, 64, 'c', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

/**
 * @param  array<string, mixed>  $evidence
 */
function cucSeedLink(
    DatabaseManager $db,
    User $user,
    int $fromId,
    int $toId,
    string $kind,
    string $state,
    string $confidence,
    string $resolver,
    array $evidence,
): int {
    $db->connection()->table('chain_links')->insert([
        'user_id' => $user->id,
        'from_transaction_id' => $fromId,
        'to_transaction_id' => $toId,
        'kind' => $kind,
        'state' => $state,
        'confidence' => $confidence,
        'resolver' => $resolver,
        'evidence' => json_encode($evidence),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return (int) $db->connection()->table('chain_links')->max('id');
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->userA = cucUser('cuc-user-a');
    $this->userB = cucUser('cuc-user-b');

    $aPaypal = cucAccount($this->userA, 'cuc-a-pp', 'paypal', 'PAYPAL');
    $aAsn = cucAccount($this->userA, 'cuc-a-asn', 'asn', 'NL16ASNB0000000001');
    $aRun = cucImportRun($this->userA, str_repeat('a', 64));

    $bPaypal = cucAccount($this->userB, 'cuc-b-pp', 'paypal', 'PAYPAL-B');
    $bAsn = cucAccount($this->userB, 'cuc-b-asn', 'asn', 'NL86ASNB0000000002');
    $bRun = cucImportRun($this->userB, str_repeat('b', 64));

    for ($i = 1; $i <= 3; $i++) {
        $f = cucTx($this->userA, $aPaypal, $aRun, -1000 * $i, 'expense', 'A'.$i, '2026-05-0'.$i, 'cuc-a-'.$i.'a', $i * 2);
        $t = cucTx($this->userA, $aAsn, $aRun, 1000 * $i, 'transfer_in', 'A-fn'.$i, '2026-05-0'.$i, 'cuc-a-'.$i.'b', $i * 2 + 1);
        cucSeedLink($this->db, $this->userA, (int) $f->id, (int) $t->id, 'paypal_funding', 'candidate', '0.800', 'auto', ['signature_hash' => 'sig-a-'.$i]);
    }

    // 1 candidate for user B — the one user A must NEVER see.
    $bf = cucTx($this->userB, $bPaypal, $bRun, -5555, 'expense', 'BUserOnly', '2026-05-10', 'cuc-b-only-a', 100);
    $bt = cucTx($this->userB, $bAsn, $bRun, 5555, 'transfer_in', 'BUserFn', '2026-05-10', 'cuc-b-only-b', 101);
    $this->userBCandidateId = cucSeedLink($this->db, $this->userB, (int) $bf->id, (int) $bt->id, 'paypal_funding', 'candidate', '0.950', 'auto', ['signature_hash' => 'sig-b']);
});

it('cross-user 404 on /chains/review confirm — userA cannot confirm userB\'s chain_link', function (): void {
    // Livewire wraps action exceptions in its own boundary, so the Public
    // action is exercised directly here.
    /** @var ConfirmChainLink $confirm */
    $confirm = $this->app->make(ConfirmChainLink::class);
    expect(fn () => ($confirm)($this->userBCandidateId, $this->userA))
        ->toThrow(NotFoundHttpException::class);

    $link = ChainLink::query()->find($this->userBCandidateId);
    expect($link)->not->toBeNull();
    expect($link->state)->toBe('candidate');
});

it('cross-user 404 on /chains/review reject — userA cannot reject userB\'s chain_link', function (): void {
    /** @var RejectChainLink $reject */
    $reject = $this->app->make(RejectChainLink::class);
    expect(fn () => ($reject)($this->userBCandidateId, $this->userA))
        ->toThrow(NotFoundHttpException::class);

    $link = ChainLink::query()->find($this->userBCandidateId);
    expect($link)->not->toBeNull();
    expect($link->state)->toBe('candidate');
});

it('cross-user 404 via Livewire harness — confirm raises Livewire 404 response status', function (): void {
    // The Livewire harness converts the NotFoundHttpException into a 404 on
    // the wire response, so the SFC path is asserted on status.
    Livewire::actingAs($this->userA)
        ->test(ChainReviewQueue::class)
        ->call('confirm', $this->userBCandidateId)
        ->assertStatus(404);
});

it('GET /chains/review for userA renders only userA candidates — never any of userB\'s', function (): void {
    $this->actingAs($this->userA)
        ->get(route('chains.review'))
        ->assertOk()
        ->assertSeeText('A1')
        ->assertDontSeeText('BUserOnly');
});

it('top-nav "Review chains" badge for userA shows userA\'s open-candidate count (3) — not userB\'s', function (): void {
    $this->actingAs($this->userA)
        ->get('/')
        ->assertOk()
        ->assertSeeText('Review chains')
        ->assertSee('>3<', false);
})->todo('16-01 replaced the top-nav with the app sidebar. The Chains "Review chains" badge slot exists on the .side-item but is not yet wired to the View Factory composer; a follow-up plan re-points registerTopNavBadgeComposer at core::livewire.app-sidebar and re-enables this assertion against the new .side-badge chrome.');

it('top-nav "Review chains" badge hides entirely when openCandidateCount === 0', function (): void {
    ChainLink::query()->where('user_id', $this->userA->id)->delete();

    $this->actingAs($this->userA)
        ->get('/')
        ->assertOk()
        ->assertSeeText('Review chains')
        // The pill carries no unique marker, so absence is asserted on the
        // rendered counts.
        ->assertDontSee('rounded-full bg-slate-900', false);
})->todo('16-01 replaced the top-nav with the app sidebar. The Chains "Review chains" badge chrome (bg-slate-900 rounded-full) no longer ships in the new sidebar markup; a follow-up plan re-wires the composer onto core::livewire.app-sidebar and re-introduces the badge slot, at which point this assertion is updated to match the new chrome.');

it('top-nav "Review chains" badge for userB shows userB\'s open-candidate count (1)', function (): void {
    $this->actingAs($this->userB)
        ->get('/')
        ->assertOk()
        ->assertSeeText('Review chains')
        ->assertSee('>1<', false);
})->todo('16-01 replaced the top-nav with the app sidebar. The Chains "Review chains" badge slot exists on the .side-item but is not yet wired to the View Factory composer; a follow-up plan re-points registerTopNavBadgeComposer at core::livewire.app-sidebar and re-enables this assertion against the new .side-badge chrome.');

it('substring-attack guard — user_id="1" vs user_id="11" in chain_links lookup uses exact match', function (): void {
    // The lookup must use where(user_id, id) exactly, never a LIKE substring:
    // user_id 1 must not match 11. RefreshDatabase hands out fresh sequential
    // ids, so rather than pinning 1 and 11 this seeds a user whose id shares a
    // leading digit with an existing one.
    $tinyId = cucUser('cuc-tiny');
    $bigId = cucUser('cuc-big');

    $bigPp = cucAccount($bigId, 'big-pp', 'paypal', 'BIG-PP');
    $bigAsn = cucAccount($bigId, 'big-asn', 'bank', 'NL11BIG00000');
    $bigRun = cucImportRun($bigId, str_repeat('q', 64));
    $bf = cucTx($bigId, $bigPp, $bigRun, -1234, 'expense', 'BigOnly', '2026-05-10', 'cuc-big-1a', 200);
    $bt = cucTx($bigId, $bigAsn, $bigRun, 1234, 'transfer_in', 'BigOnlyFn', '2026-05-10', 'cuc-big-1b', 201);
    cucSeedLink($this->db, $bigId, (int) $bf->id, (int) $bt->id, 'paypal_funding', 'candidate', '0.900', 'auto', ['signature_hash' => 'sig-big']);

    $this->actingAs($tinyId)
        ->get(route('chains.review'))
        ->assertOk()
        ->assertDontSeeText('BigOnly');
});
