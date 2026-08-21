<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\Exceptions\ChainLinkRequiresConcretePartnerException;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Actions\ConfirmChainLink;
use Modules\Chains\Public\Actions\RejectChainLink;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function rclUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function rclAccount(User $user, string $slug, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'rcl '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function rclImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rcl.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

function rclTx(User $user, Account $account, ImportRun $run, int $amountMinor, string $type, string $fingerprintSeed, int $rowIndex): Transaction
{
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => '2026-05-'.str_pad((string) min(28, max(1, $rowIndex)), 2, '0', STR_PAD_LEFT),
        'booked_at' => '2026-05-'.str_pad((string) min(28, max(1, $rowIndex)), 2, '0', STR_PAD_LEFT).' 12:00:00',
        'value_date' => '2026-05-'.str_pad((string) min(28, max(1, $rowIndex)), 2, '0', STR_PAD_LEFT),
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
        'fingerprint' => str_pad($fingerprintSeed, 64, 'r', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

function rclLink(DatabaseManager $db, User $user, int $from, int $to, string $state, string $signatureHash): int
{
    $db->connection()->table('chain_links')->insert([
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

    return (int) $db->connection()->table('chain_links')->max('id');
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = rclUser('reject-chain-link');
    $this->paypal = rclAccount($this->user, 'rcl-paypal', 'paypal', 'PAYPAL');
    $this->asn = rclAccount($this->user, 'rcl-asn', 'asn', 'NL57ASNB0123456789');
    $this->run = rclImportRun($this->user, str_repeat('1', 64));

    /** @var RejectChainLink $reject */
    $reject = $this->app->make(RejectChainLink::class);
    $this->reject = $reject;
});

it('sets state to rejected for the targeted pair', function (): void {
    $from = rclTx($this->user, $this->paypal, $this->run, -1000, 'expense', 'r1', 1);
    $to = rclTx($this->user, $this->asn, $this->run, 1000, 'transfer_in', 'r2', 2);
    $linkId = rclLink($this->db, $this->user, (int) $from->id, (int) $to->id, 'candidate', 'sig-rej');

    ($this->reject)($linkId, $this->user);

    /** @var ChainLink $link */
    $link = ChainLink::query()->findOrFail($linkId);
    expect($link->state)->toBe('rejected');
});

it('leaves the signature counter neutral — does NOT demote same-signature confirmed rows', function (): void {
    $signature = 'sig-rej-neutral';

    // Three already-confirmed rows of the same signature.
    for ($i = 1; $i <= 3; $i++) {
        $f = rclTx($this->user, $this->paypal, $this->run, -100 * $i, 'expense', 's'.$i, $i);
        $t = rclTx($this->user, $this->asn, $this->run, 100 * $i, 'transfer_in', 's'.$i.'b', $i + 10);
        rclLink($this->db, $this->user, (int) $f->id, (int) $t->id, 'confirmed', $signature);
    }

    $fc = rclTx($this->user, $this->paypal, $this->run, -400, 'expense', 's4', 4);
    $tc = rclTx($this->user, $this->asn, $this->run, 400, 'transfer_in', 's4b', 14);
    $rejectId = rclLink($this->db, $this->user, (int) $fc->id, (int) $tc->id, 'candidate', $signature);

    ($this->reject)($rejectId, $this->user);

    expect(ChainLink::query()
        ->where('state', 'confirmed')
        ->where('user_id', $this->user->id)
        ->count())->toBe(3);
});

it('does NOT trigger auto-promotion via rejection (per-pair only)', function (): void {
    $signature = 'sig-rej-no-promote';

    // Two confirmed + one candidate we will REJECT (not confirm).
    for ($i = 1; $i <= 2; $i++) {
        $f = rclTx($this->user, $this->paypal, $this->run, -100 * $i, 'expense', 'u'.$i, $i);
        $t = rclTx($this->user, $this->asn, $this->run, 100 * $i, 'transfer_in', 'u'.$i.'b', $i + 10);
        rclLink($this->db, $this->user, (int) $f->id, (int) $t->id, 'confirmed', $signature);
    }
    $fr = rclTx($this->user, $this->paypal, $this->run, -300, 'expense', 'u3', 3);
    $tr = rclTx($this->user, $this->asn, $this->run, 300, 'transfer_in', 'u3b', 13);
    $rejectId = rclLink($this->db, $this->user, (int) $fr->id, (int) $tr->id, 'candidate', $signature);

    // Another same-signature candidate that, if rejection counted, might
    // auto-promote — but it must NOT.
    $fs = rclTx($this->user, $this->paypal, $this->run, -400, 'expense', 'u4', 4);
    $ts = rclTx($this->user, $this->asn, $this->run, 400, 'transfer_in', 'u4b', 14);
    $stillCandidateId = rclLink($this->db, $this->user, (int) $fs->id, (int) $ts->id, 'candidate', $signature);

    ($this->reject)($rejectId, $this->user);

    /** @var ChainLink $still */
    $still = ChainLink::query()->findOrFail($stillCandidateId);
    expect($still->state)->toBe('candidate');

    // Confirming the other candidate lifts the count from 2 to 3 and DOES
    // auto-promote, proving the rejection shifted the threshold neither way.
    /** @var ConfirmChainLink $confirm */
    $confirm = $this->app->make(ConfirmChainLink::class);
    $confirm($stillCandidateId, $this->user);

    /** @var ChainLink $finalState */
    $finalState = ChainLink::query()->findOrFail($stillCandidateId);
    expect($finalState->state)->toBe('confirmed');
});

it('raises NotFoundHttpException on cross-user invocation (404)', function (): void {
    $other = rclUser('rcl-other');
    $oAcc = rclAccount($other, 'rcl-other-acc', 'paypal', 'OTHER');
    $oRun = rclImportRun($other, str_repeat('2', 64));
    $f = rclTx($other, $oAcc, $oRun, -100, 'expense', 'rx1', 1);
    $t = rclTx($other, $oAcc, $oRun, 100, 'transfer_in', 'rx2', 2);
    $linkId = rclLink($this->db, $other, (int) $f->id, (int) $t->id, 'candidate', 'sig-x');

    expect(fn () => ($this->reject)($linkId, $this->user))
        ->toThrow(NotFoundHttpException::class);
});

it('throws ChainLinkRequiresConcretePartnerException when rejecting a hint row with NULL to_transaction_id', function (): void {
    $from = rclTx($this->user, $this->paypal, $this->run, -1000, 'expense', 'rh1', 1);
    $this->db->connection()->table('chain_links')->insert([
        'user_id' => $this->user->id,
        'from_transaction_id' => $from->id,
        'to_transaction_id' => null,
        'kind' => 'ics_bulk_settle',
        'state' => 'candidate',
        'confidence' => '0.900',
        'resolver' => 'auto',
        'evidence' => json_encode(['tolerance_used' => 'exceeded', 'signature_hash' => 'sig-rhint']),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
    $hintId = (int) $this->db->connection()->table('chain_links')->max('id');

    expect(fn () => ($this->reject)($hintId, $this->user))
        ->toThrow(ChainLinkRequiresConcretePartnerException::class);

    /** @var ChainLink $unchanged */
    $unchanged = ChainLink::query()->findOrFail($hintId);
    expect($unchanged->state)->toBe('candidate');
});
