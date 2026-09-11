<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Sync\Public\Events\PeerRowsApplied;

// A pair is two columns on two rows and the schema holds only the foreign key.
// One device retypes a leg it does not yet know is paired, the other keeps the
// link it wrote, and the merge seats both: one leg names a partner that has
// let go. That leg is counted by no flow and no sweep can see it to re-pair.

function halfPairUser(string $name): User
{
    return User::query()->create([
        'username' => $name,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function halfPairAccount(User $user, string $slug, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $slug,
        'slug' => $slug,
        'kind' => AccountKind::Bank->value,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function halfPairRun(User $user, string $slug): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/'.$slug.'.csv',
        'sha256' => hash('sha256', $slug),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function halfPairTx(User $user, Account $account, ImportRun $run, array $overrides = []): Transaction
{
    static $row = 0;
    $row++;

    return Transaction::query()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => TransactionType::TransferOut->value,
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -10000,
        'currency' => 'EUR',
        'settled_amount_minor' => -10000,
        'settled_currency' => 'EUR',
        'counterparty_iban' => null,
        'counterparty_name' => 'Half pair '.$row,
        'counterparty_normalized' => 'half-pair-'.$row,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $row,
        'fingerprint' => str_pad((string) $row, 64, 'h', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ], $overrides));
}

function halfPairLink(int $legId, ?int $partnerId): void
{
    DB::table('transactions')->where('id', $legId)->update(['pair_transaction_id' => $partnerId]);
}

function halfPairPartnerOf(int $legId): ?int
{
    $stored = DB::table('transactions')->where('id', $legId)->value('pair_transaction_id');

    return $stored === null ? null : (int) $stored;
}

function halfPairTypeOf(int $legId): string
{
    return (string) DB::table('transactions')->where('id', $legId)->value('type');
}

/**
 * @param  list<int>  $pks
 */
function halfPairAnnounce(int $userId, array $pks): void
{
    app('events')->dispatch(new PeerRowsApplied(
        userId: $userId,
        updated: ['transactions' => $pks],
    ));
}

beforeEach(function (): void {
    $this->user = halfPairUser('half-pair-reader');
    $this->asn = halfPairAccount($this->user, 'half-pair-asn', 'NL57ASNB0123456789');
    $this->paypal = halfPairAccount($this->user, 'half-pair-paypal', 'NL09ASNB0987654321');
    $this->run = halfPairRun($this->user, 'half-pair');
});

/**
 * The state the merge leaves behind: the retyped leg let go, the other did not.
 *
 * @return array{0: Transaction, 1: Transaction}
 */
function halfPairAfterARetype(): array
{
    $test = test();

    $retyped = halfPairTx($test->user, $test->asn, $test->run, [
        'type' => TransactionType::Expense->value,
    ]);
    $stillNaming = halfPairTx($test->user, $test->paypal, $test->run, [
        'type' => TransactionType::TransferIn->value,
        'amount_minor' => 10000,
        'settled_amount_minor' => 10000,
        'pair_transaction_id' => $retyped->id,
    ]);

    return [$retyped, $stillNaming];
}

it('clears the link on a leg whose partner is no longer a transfer', function (): void {
    [$retyped, $stillNaming] = halfPairAfterARetype();

    expect(halfPairPartnerOf($stillNaming->id))->toBe($retyped->id);

    halfPairAnnounce((int) $this->user->id, [$stillNaming->id]);

    expect(halfPairPartnerOf($stillNaming->id))->toBeNull()
        ->and(halfPairTypeOf($stillNaming->id))->toBe(TransactionType::TransferIn->value)
        ->and(halfPairPartnerOf($retyped->id))->toBeNull()
        ->and(halfPairTypeOf($retyped->id))->toBe(TransactionType::Expense->value);
});

// The other device hears about the same break from the opposite side: what
// arrived there is the RETYPE, so the only row the announcement names is the
// one being named. Reading the touched rows alone repairs one device only.
it('clears it when the replay named only the row the half pair points at', function (): void {
    [$retyped, $stillNaming] = halfPairAfterARetype();

    halfPairAnnounce((int) $this->user->id, [$retyped->id]);

    expect(halfPairPartnerOf($stillNaming->id))->toBeNull()
        ->and(halfPairTypeOf($stillNaming->id))->toBe(TransactionType::TransferIn->value);
});

// The third-row half of the invariant: two devices paired the same leg to two
// different partners, LWW seated one of them, and the loser is left naming a
// row that names somebody else.
it('clears a leg whose partner has paired with a third row', function (): void {
    $loser = halfPairTx($this->user, $this->asn, $this->run, [
        'type' => TransactionType::TransferOut->value,
    ]);
    $shared = halfPairTx($this->user, $this->paypal, $this->run, [
        'type' => TransactionType::TransferIn->value,
        'amount_minor' => 10000,
        'settled_amount_minor' => 10000,
    ]);
    $winner = halfPairTx($this->user, $this->asn, $this->run, [
        'type' => TransactionType::TransferOut->value,
    ]);

    halfPairLink($loser->id, $shared->id);
    halfPairLink($shared->id, $winner->id);
    halfPairLink($winner->id, $shared->id);

    halfPairAnnounce((int) $this->user->id, [$shared->id]);

    expect(halfPairPartnerOf($loser->id))->toBeNull()
        ->and(halfPairPartnerOf($shared->id))->toBe($winner->id)
        ->and(halfPairPartnerOf($winner->id))->toBe($shared->id);
});

// A control: a pair that names itself both ways is what a pair is supposed to
// look like, and it passes unchanged whether or not the listener is registered.
// Without it, a listener that nulled every link would still read as green.
it('leaves a pair that names itself both ways alone', function (): void {
    $out = halfPairTx($this->user, $this->asn, $this->run, [
        'type' => TransactionType::TransferOut->value,
    ]);
    $in = halfPairTx($this->user, $this->paypal, $this->run, [
        'type' => TransactionType::TransferIn->value,
        'amount_minor' => 10000,
        'settled_amount_minor' => 10000,
        'pair_transaction_id' => $out->id,
    ]);
    halfPairLink($out->id, $in->id);

    halfPairAnnounce((int) $this->user->id, [$out->id, $in->id]);

    expect(halfPairPartnerOf($out->id))->toBe($in->id)
        ->and(halfPairPartnerOf($in->id))->toBe($out->id)
        ->and(halfPairTypeOf($out->id))->toBe(TransactionType::TransferOut->value)
        ->and(halfPairTypeOf($in->id))->toBe(TransactionType::TransferIn->value);
});

// A control: a leg with no link is not a half pair, and an unpaired transfer
// is the ordinary state of every row the orphan sweep has yet to reach.
it('leaves a leg that names nobody alone', function (): void {
    $orphan = halfPairTx($this->user, $this->asn, $this->run, [
        'type' => TransactionType::TransferOut->value,
    ]);

    halfPairAnnounce((int) $this->user->id, [$orphan->id]);

    expect(halfPairPartnerOf($orphan->id))->toBeNull()
        ->and(halfPairTypeOf($orphan->id))->toBe(TransactionType::TransferOut->value);
});

// A guard, and a control: a partner still typed as a transfer with an empty
// link wears the same shape as a link the deferral will deliver in the next
// batch. Clearing on that would unpair a healthy pair whose two Sets straddled
// a batch boundary, so this one is left exactly as it arrived.
it('leaves a leg whose partner may still be about to name it back', function (): void {
    $waiting = halfPairTx($this->user, $this->asn, $this->run, [
        'type' => TransactionType::TransferOut->value,
    ]);
    $arrived = halfPairTx($this->user, $this->paypal, $this->run, [
        'type' => TransactionType::TransferIn->value,
        'amount_minor' => 10000,
        'settled_amount_minor' => 10000,
        'pair_transaction_id' => $waiting->id,
    ]);

    halfPairAnnounce((int) $this->user->id, [$arrived->id]);

    expect(halfPairPartnerOf($arrived->id))->toBe($waiting->id)
        ->and(halfPairPartnerOf($waiting->id))->toBeNull();
});

// A guard, and a control: the replay ran for one reader, and another reader's
// half pair is not this event's to repair even when its id is announced.
it('stays inside the user the replay ran for', function (): void {
    $other = halfPairUser('half-pair-other-reader');
    $otherAccount = halfPairAccount($other, 'half-pair-other', 'NL21ASNB0000000001');
    $otherRun = halfPairRun($other, 'half-pair-other');

    $retyped = halfPairTx($other, $otherAccount, $otherRun, [
        'type' => TransactionType::Expense->value,
    ]);
    $stillNaming = halfPairTx($other, $otherAccount, $otherRun, [
        'type' => TransactionType::TransferIn->value,
        'amount_minor' => 10000,
        'settled_amount_minor' => 10000,
        'pair_transaction_id' => $retyped->id,
    ]);

    halfPairAnnounce((int) $this->user->id, [$retyped->id, $stillNaming->id]);

    expect(halfPairPartnerOf($stillNaming->id))->toBe($retyped->id)
        ->and(halfPairTypeOf($stillNaming->id))->toBe(TransactionType::TransferIn->value);
});
