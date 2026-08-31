<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\Exceptions\ChainLinkRequiresConcretePartnerException;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Actions\ConfirmChainLink;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function cclUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function cclAccount(User $user, string $slug, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ccl '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function cclImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ccl.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

function cclTx(
    User $user,
    Account $account,
    ImportRun $run,
    int $amountMinor,
    string $type,
    string $fingerprintSeed,
    int $rowIndex,
): Transaction {
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
        'fingerprint' => str_pad($fingerprintSeed, 64, 'c', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

/**
 * @param  array<string, mixed>  $evidence
 */
function cclLink(DatabaseManager $db, User $user, int $from, int $to, string $state, string $signatureHash): int
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

    $this->user = cclUser('confirm-chain-link');
    $this->paypal = cclAccount($this->user, 'ccl-paypal', 'paypal', 'PAYPAL');
    $this->asn = cclAccount($this->user, 'ccl-asn', 'asn', 'NL57ASNB0123456789');
    $this->run = cclImportRun($this->user, str_repeat('1', 64));

    /** @var ConfirmChainLink $confirm */
    $confirm = $this->app->make(ConfirmChainLink::class);
    $this->confirm = $confirm;
});

it('promotes a single candidate to confirmed (no auto-promotion below threshold)', function (): void {
    $from = cclTx($this->user, $this->paypal, $this->run, -1000, 'expense', 'a1', 1);
    $to = cclTx($this->user, $this->asn, $this->run, 1000, 'transfer_in', 'a2', 2);
    $linkId = cclLink($this->db, $this->user, (int) $from->id, (int) $to->id, 'candidate', 'sig-once');

    ($this->confirm)($linkId, $this->user);

    /** @var ChainLink $link */
    $link = ChainLink::query()->findOrFail($linkId);
    expect($link->state)->toBe('confirmed');
    // Resolver preserved (not flipped to 'user' or 'rule' for a single confirm).
    expect($link->resolver)->toBe('auto');
});

it('auto-promotes other same-signature candidates at the 3rd confirmation', function (): void {
    $signature = 'sig-auto-promote';

    // Two already-confirmed rows + this confirm = the 3-confirm threshold.
    for ($i = 1; $i <= 2; $i++) {
        $f = cclTx($this->user, $this->paypal, $this->run, -100 * $i, 'expense', 'p'.$i, $i);
        $t = cclTx($this->user, $this->asn, $this->run, 100 * $i, 'transfer_in', 't'.$i, $i + 10);
        cclLink($this->db, $this->user, (int) $f->id, (int) $t->id, 'confirmed', $signature);
    }

    $f3 = cclTx($this->user, $this->paypal, $this->run, -300, 'expense', 'p3', 3);
    $t3 = cclTx($this->user, $this->asn, $this->run, 300, 'transfer_in', 't3', 13);
    $thirdId = cclLink($this->db, $this->user, (int) $f3->id, (int) $t3->id, 'candidate', $signature);

    $otherCandidateIds = [];
    for ($i = 4; $i <= 5; $i++) {
        $f = cclTx($this->user, $this->paypal, $this->run, -100 * $i, 'expense', 'p'.$i, $i);
        $t = cclTx($this->user, $this->asn, $this->run, 100 * $i, 'transfer_in', 't'.$i, $i + 10);
        $otherCandidateIds[] = cclLink($this->db, $this->user, (int) $f->id, (int) $t->id, 'candidate', $signature);
    }

    $fOther = cclTx($this->user, $this->paypal, $this->run, -600, 'expense', 'po', 6);
    $tOther = cclTx($this->user, $this->asn, $this->run, 600, 'transfer_in', 'to', 16);
    $differentSignatureId = cclLink($this->db, $this->user, (int) $fOther->id, (int) $tOther->id, 'candidate', 'sig-different');

    ($this->confirm)($thirdId, $this->user);

    // The third row itself is confirmed (resolver stays 'auto' — only OTHER auto-promoted rows are 'rule').
    /** @var ChainLink $third */
    $third = ChainLink::query()->findOrFail($thirdId);
    expect($third->state)->toBe('confirmed');
    expect($third->resolver)->toBe('auto');

    foreach ($otherCandidateIds as $id) {
        /** @var ChainLink $row */
        $row = ChainLink::query()->findOrFail($id);
        expect($row->state)->toBe('confirmed');
        expect($row->resolver)->toBe('rule');
    }

    /** @var ChainLink $differentRow */
    $differentRow = ChainLink::query()->findOrFail($differentSignatureId);
    expect($differentRow->state)->toBe('candidate');
});

it('does NOT auto-promote when same-signature confirmed count is below 3', function (): void {
    $signature = 'sig-below-threshold';

    // 1 confirmed already + the one we confirm = 2 < 3 → no auto-promotion.
    $f1 = cclTx($this->user, $this->paypal, $this->run, -100, 'expense', 'q1', 1);
    $t1 = cclTx($this->user, $this->asn, $this->run, 100, 'transfer_in', 'q2', 11);
    cclLink($this->db, $this->user, (int) $f1->id, (int) $t1->id, 'confirmed', $signature);

    $f2 = cclTx($this->user, $this->paypal, $this->run, -200, 'expense', 'q3', 2);
    $t2 = cclTx($this->user, $this->asn, $this->run, 200, 'transfer_in', 'q4', 12);
    $candidateId = cclLink($this->db, $this->user, (int) $f2->id, (int) $t2->id, 'candidate', $signature);

    $f3 = cclTx($this->user, $this->paypal, $this->run, -300, 'expense', 'q5', 3);
    $t3 = cclTx($this->user, $this->asn, $this->run, 300, 'transfer_in', 'q6', 13);
    $otherCandidateId = cclLink($this->db, $this->user, (int) $f3->id, (int) $t3->id, 'candidate', $signature);

    ($this->confirm)($candidateId, $this->user);

    /** @var ChainLink $other */
    $other = ChainLink::query()->findOrFail($otherCandidateId);
    expect($other->state)->toBe('candidate');
});

it('raises NotFoundHttpException on cross-user invocation (404)', function (): void {
    $other = cclUser('ccl-other');
    $otherAcc = cclAccount($other, 'ccl-other-acc', 'paypal', 'OTHER');
    $otherRun = cclImportRun($other, str_repeat('2', 64));
    $f = cclTx($other, $otherAcc, $otherRun, -100, 'expense', 'oz1', 1);
    $t = cclTx($other, $otherAcc, $otherRun, 100, 'transfer_in', 'oz2', 2);
    $linkId = cclLink($this->db, $other, (int) $f->id, (int) $t->id, 'candidate', 'sig-other');

    expect(fn () => ($this->confirm)($linkId, $this->user))
        ->toThrow(NotFoundHttpException::class);
});

it('whereJsonContains finds the signature on this SQLite build', function (): void {
    // The smoke test proves JSON1 works; this runs the same whereJsonContains
    // path the action itself takes.
    $signature = 'sig-json-contains';

    for ($i = 1; $i <= 3; $i++) {
        $f = cclTx($this->user, $this->paypal, $this->run, -100 * $i, 'expense', 'j'.$i, $i);
        $t = cclTx($this->user, $this->asn, $this->run, 100 * $i, 'transfer_in', 'j'.$i.'b', $i + 10);
        if ($i === 3) {
            $thirdId = cclLink($this->db, $this->user, (int) $f->id, (int) $t->id, 'candidate', $signature);
        } else {
            cclLink($this->db, $this->user, (int) $f->id, (int) $t->id, 'confirmed', $signature);
        }
    }

    $fNext = cclTx($this->user, $this->paypal, $this->run, -400, 'expense', 'jx', 4);
    $tNext = cclTx($this->user, $this->asn, $this->run, 400, 'transfer_in', 'jxb', 14);
    $autoId = cclLink($this->db, $this->user, (int) $fNext->id, (int) $tNext->id, 'candidate', $signature);

    ($this->confirm)($thirdId, $this->user);

    /** @var ChainLink $auto */
    $auto = ChainLink::query()->findOrFail($autoId);
    expect($auto->state)->toBe('confirmed');
    expect($auto->resolver)->toBe('rule');
});

it('throws ChainLinkRequiresConcretePartnerException when confirming a hint row with NULL to_transaction_id', function (): void {
    // The chain_links_to_transaction_id_check_update trigger refuses the state
    // flip on a NULL-endpoint row, so the action must guard before save() and
    // raise a typed exception rather than a raw SQLSTATE 23000.
    $from = cclTx($this->user, $this->paypal, $this->run, -1000, 'expense', 'h1', 1);
    $this->db->connection()->table('chain_links')->insert([
        'user_id' => $this->user->id,
        'from_transaction_id' => $from->id,
        'to_transaction_id' => null,
        'kind' => 'ics_bulk_settle',
        'state' => 'candidate',
        'confidence' => '0.900',
        'resolver' => 'auto',
        'evidence' => json_encode(['tolerance_used' => 'exceeded', 'signature_hash' => 'sig-hint']),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
    $hintId = (int) $this->db->connection()->table('chain_links')->max('id');

    expect(fn () => ($this->confirm)($hintId, $this->user))
        ->toThrow(ChainLinkRequiresConcretePartnerException::class);

    /** @var ChainLink $unchanged */
    $unchanged = ChainLink::query()->findOrFail($hintId);
    expect($unchanged->state)->toBe('candidate');
});

// Every confirmed ics_bulk_settle link on a statement carries that statement's
// signature hash, and so does the exceeded-tolerance hint on it. The
// auto-promotion UPDATE selected by hash alone, so the hint went with them —
// straight into the NULL-endpoint trigger, aborting the reader's confirm.
it('leaves a NULL-endpoint hint alone when the auto-promotion sweep fires on its signature', function (): void {
    $sharedHash = 'sig-shared-statement';

    $hintSource = cclTx($this->user, $this->asn, $this->run, -5000, 'transfer_out', 'promote-hint', 90);
    $this->db->connection()->table('chain_links')->insert([
        'user_id' => $this->user->id,
        'from_transaction_id' => $hintSource->id,
        'to_transaction_id' => null,
        'kind' => 'ics_bulk_settle',
        'state' => 'candidate',
        'confidence' => '0.900',
        'resolver' => 'auto',
        'evidence' => json_encode(['tolerance_used' => 'exceeded', 'signature_hash' => $sharedHash]),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
    $hintId = (int) $this->db->connection()->table('chain_links')->max('id');

    for ($i = 0; $i < 2; $i++) {
        $from = cclTx($this->user, $this->paypal, $this->run, -1000, 'expense', 'promote-from-'.$i, 91 + $i);
        $to = cclTx($this->user, $this->asn, $this->run, 1000, 'transfer_in', 'promote-to-'.$i, 95 + $i);
        cclLink($this->db, $this->user, (int) $from->id, (int) $to->id, 'confirmed', $sharedHash);
    }

    $from = cclTx($this->user, $this->paypal, $this->run, -1000, 'expense', 'promote-from-last', 99);
    $to = cclTx($this->user, $this->asn, $this->run, 1000, 'transfer_in', 'promote-to-last', 100);
    $candidateId = cclLink($this->db, $this->user, (int) $from->id, (int) $to->id, 'candidate', $sharedHash);

    ($this->confirm)($candidateId, $this->user);

    /** @var ChainLink $hint */
    $hint = ChainLink::query()->findOrFail($hintId);
    expect($hint->state)->toBe('candidate');
    expect($hint->to_transaction_id)->toBeNull();
});
