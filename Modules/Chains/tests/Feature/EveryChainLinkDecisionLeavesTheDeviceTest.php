<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Public\Actions\ConfirmChainLink;
use Modules\Chains\Public\Actions\RejectChainLink;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Public\Events\EntityMutated;

// Confirm and reject are the reader's decisions about a link, and the
// auto-promotion sweep makes the same decision for every sibling candidate at
// once. All three were invisible to the peer: the row travelled in the pairing
// backfill and then never moved again.

function ecdUser(): User
{
    return User::query()->create([
        'username' => 'chain-capture-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function ecdTransaction(User $user, Account $account, ImportRun $run, int $amountMinor, string $type, int $rowIndex): Transaction
{
    $day = str_pad((string) min(28, max(1, $rowIndex)), 2, '0', STR_PAD_LEFT);

    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => '2026-05-'.$day,
        'booked_at' => '2026-05-'.$day.' 12:00:00',
        'value_date' => '2026-05-'.$day,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'CP '.$rowIndex,
        'counterparty_normalized' => 'cp-'.$rowIndex,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => hash('sha256', 'chain-capture-'.$rowIndex.'-'.bin2hex(random_bytes(4))),
        'fingerprint_version' => 3,
    ]);
}

// Written with the id the resolvers would derive, because that is the id every
// later op has to name.
function ecdLink(DatabaseManager $db, User $user, int $from, int $to, string $state, string $signatureHash): int
{
    $id = ChainLinkInsertHelper::idFor((int) $user->id, $from, $to, 'paypal_funding');

    $db->connection()->table('chain_links')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'from_transaction_id' => $from,
        'to_transaction_id' => $to,
        'kind' => 'paypal_funding',
        'state' => $state,
        'confidence' => $state === 'confirmed' ? '1.000' : '0.800',
        'resolver' => 'auto',
        'evidence' => json_encode(['signature_hash' => $signatureHash]),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return $id;
}

// A real listener rather than Event::fake(): the action singletons are built on
// first resolution and hold the dispatcher instance they were constructed with,
// so a fake swapped in afterwards would see nothing they emit.
/**
 * @return ArrayObject<int, EntityMutated>
 */
function ecdRecord(): ArrayObject
{
    /** @var ArrayObject<int, EntityMutated> $captured */
    $captured = new ArrayObject;

    app(Dispatcher::class)->listen(
        EntityMutated::class,
        static function (EntityMutated $event) use ($captured): void {
            $captured[] = $event;
        },
    );

    return $captured;
}

/**
 * @param  ArrayObject<int, EntityMutated>  $captured
 * @return list<int>
 */
function ecdCapturedChainLinkPks(ArrayObject $captured): array
{
    $pks = [];

    foreach ($captured as $event) {
        if ($event->table === 'chain_links') {
            $pks[] = (int) $event->pk;
        }
    }

    sort($pks);

    return $pks;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = ecdUser();
    $this->paypal = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'chain capture paypal',
        'slug' => 'chain-capture-paypal-'.bin2hex(random_bytes(3)),
        'kind' => 'paypal',
        'iban' => 'PAYPAL'.strtoupper(bin2hex(random_bytes(3))),
        'default_currency' => 'EUR',
    ]);
    $this->asn = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'chain capture asn',
        'slug' => 'chain-capture-asn-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/chain-capture.csv',
        'sha256' => hash('sha256', 'chain-capture-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('captures a confirm', function (): void {
    $from = ecdTransaction($this->user, $this->paypal, $this->run, -1000, 'expense', 1);
    $to = ecdTransaction($this->user, $this->asn, $this->run, 1000, 'transfer_in', 2);
    $linkId = ecdLink($this->db, $this->user, (int) $from->id, (int) $to->id, 'candidate', 'sig-once');

    $captured = ecdRecord();

    app(ConfirmChainLink::class)($linkId, $this->user);

    expect(ecdCapturedChainLinkPks($captured))->toBe([$linkId]);
});

it('captures a reject', function (): void {
    $from = ecdTransaction($this->user, $this->paypal, $this->run, -1000, 'expense', 3);
    $to = ecdTransaction($this->user, $this->asn, $this->run, 1000, 'transfer_in', 4);
    $linkId = ecdLink($this->db, $this->user, (int) $from->id, (int) $to->id, 'candidate', 'sig-reject');

    $captured = ecdRecord();

    app(RejectChainLink::class)($linkId, $this->user);

    expect(ecdCapturedChainLinkPks($captured))->toBe([$linkId]);
});

// The auto-promotion is one UPDATE over every sibling candidate, so the ids it
// touched are only knowable before the predicate stops matching them. Read
// after, it named nothing and the peer kept showing rows this device confirmed.
it('captures every row the third confirmation auto-promotes, not just the one clicked', function (): void {
    $signature = 'sig-auto-promote';

    for ($i = 1; $i <= 2; $i++) {
        $from = ecdTransaction($this->user, $this->paypal, $this->run, -100 * $i, 'expense', $i);
        $to = ecdTransaction($this->user, $this->asn, $this->run, 100 * $i, 'transfer_in', $i + 10);
        ecdLink($this->db, $this->user, (int) $from->id, (int) $to->id, 'confirmed', $signature);
    }

    $thirdFrom = ecdTransaction($this->user, $this->paypal, $this->run, -300, 'expense', 3);
    $thirdTo = ecdTransaction($this->user, $this->asn, $this->run, 300, 'transfer_in', 13);
    $thirdId = ecdLink($this->db, $this->user, (int) $thirdFrom->id, (int) $thirdTo->id, 'candidate', $signature);

    $promoted = [];
    for ($i = 4; $i <= 5; $i++) {
        $from = ecdTransaction($this->user, $this->paypal, $this->run, -100 * $i, 'expense', $i);
        $to = ecdTransaction($this->user, $this->asn, $this->run, 100 * $i, 'transfer_in', $i + 10);
        $promoted[] = ecdLink($this->db, $this->user, (int) $from->id, (int) $to->id, 'candidate', $signature);
    }

    $captured = ecdRecord();

    app(ConfirmChainLink::class)($thirdId, $this->user);

    $expected = [...$promoted, $thirdId];
    sort($expected);

    expect(ecdCapturedChainLinkPks($captured))->toBe($expected);
});
