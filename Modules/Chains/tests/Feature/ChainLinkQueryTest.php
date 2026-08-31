<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Public\Dto\ChainLinkRow;
use Modules\Chains\Public\Dto\ChainTree;
use Modules\Chains\Public\Dto\ChainTreeNode;
use Modules\Chains\Public\Enums\ConfidenceTier;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function clqUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function clqAccount(User $user, string $slug, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'clq '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function clqImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/clq.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

function clqTx(
    User $user,
    Account $account,
    ImportRun $run,
    int $amountMinor,
    string $type,
    string $counterpartyName,
    string $counterpartyNormalized,
    string $postedAt,
    string $fingerprintSeed,
    int $rowIndex = 1,
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
        'counterparty_normalized' => $counterpartyNormalized,
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
function clqSeedLink(
    DatabaseManager $db,
    User $user,
    int $fromId,
    ?int $toId,
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

    $this->user = clqUser('chainlink-query');
    $this->paypal = clqAccount($this->user, 'clq-paypal', 'paypal', 'PAYPAL');
    $this->asn = clqAccount($this->user, 'clq-asn', 'asn', 'NL57ASNB0123456789');
    $this->ics = clqAccount($this->user, 'clq-ics', 'ics_card', 'ICS-CARD');
    $this->run = clqImportRun($this->user, str_repeat('1', 64));

    /** @var ChainLinkQuery $query */
    $query = $this->app->make(ChainLinkQuery::class);
    $this->query = $query;
});

it('forTransaction assembles top-down chain tree (root → funder)', function (): void {
    $paypalExpense = clqTx($this->user, $this->paypal, $this->run, -2500, 'expense', 'Spotify', 'spotify', '2026-05-10', 'a1', 1);
    $asnTransfer = clqTx($this->user, $this->asn, $this->run, 2500, 'transfer_in', 'PayPal', 'paypal', '2026-05-10', 'a2', 2);

    clqSeedLink(
        $this->db, $this->user, (int) $paypalExpense->id, (int) $asnTransfer->id,
        'paypal_funding', 'confirmed', '1.000', 'auto',
        ['matched_iban' => 'NL57ASNB0123456789', 'signature_hash' => 'h1'],
    );

    $tree = $this->query->forTransaction((int) $paypalExpense->id, $this->user);

    expect($tree)->toBeInstanceOf(ChainTree::class);
    expect($tree->rootTransactionId)->toBe((int) $paypalExpense->id);
    expect($tree->nodes)->toHaveCount(2);
    expect($tree->nodes[0])->toBeInstanceOf(ChainTreeNode::class);
    expect($tree->nodes[0]->kind)->toBe('root');
    expect($tree->nodes[0]->transactionId)->toBe((int) $paypalExpense->id);
    expect($tree->nodes[1]->kind)->toBe('paypal_funding');
    expect($tree->nodes[1]->transactionId)->toBe((int) $asnTransfer->id);
    expect($tree->nodes[1]->confidenceTier)->toBe(ConfidenceTier::Deterministic);
});

it('forTransaction walks BOTH directions — rooting on the funder side surfaces the funded transaction', function (): void {
    // The walker once followed forward edges only, so rooting on the link's
    // `to` side returned the root node alone.
    $paypalExpense = clqTx($this->user, $this->paypal, $this->run, -2500, 'expense', 'Spotify', 'spotify', '2026-05-10', 'bd1', 1);
    $asnTransfer = clqTx($this->user, $this->asn, $this->run, -2500, 'transfer_out', 'PayPal SARL', 'paypal-sarl', '2026-05-10', 'bd2', 2);
    clqSeedLink(
        $this->db, $this->user, (int) $paypalExpense->id, (int) $asnTransfer->id,
        'paypal_funding', 'confirmed', '1.000', 'auto', ['signature_hash' => 'bd-h1'],
    );

    $tree = $this->query->forTransaction((int) $asnTransfer->id, $this->user);

    expect($tree->nodes)->toHaveCount(2);
    expect($tree->nodes[0]->transactionId)->toBe((int) $asnTransfer->id);
    expect($tree->nodes[1]->transactionId)->toBe((int) $paypalExpense->id);
    expect($tree->nodes[1]->kind)->toBe('paypal_funding');
});

it('allChainsForUser returns every non-rejected chain_link with both endpoints hydrated, sorted by recency', function (): void {
    $f1 = clqTx($this->user, $this->paypal, $this->run, -1000, 'expense', 'Older', 'older', '2026-05-10', 'all1', 1);
    $t1 = clqTx($this->user, $this->asn, $this->run, -1000, 'transfer_out', 'PayPal SARL', 'p-sarl-1', '2026-05-10', 'all2', 2);
    $f2 = clqTx($this->user, $this->paypal, $this->run, -1500, 'expense', 'Newer', 'newer', '2026-05-11', 'all3', 3);
    $t2 = clqTx($this->user, $this->asn, $this->run, -1500, 'transfer_out', 'PayPal SARL', 'p-sarl-2', '2026-05-11', 'all4', 4);

    clqSeedLink($this->db, $this->user, (int) $f1->id, (int) $t1->id, 'paypal_funding', 'confirmed', '1.000', 'auto', ['signature_hash' => 'all-sig-1']);
    // Both inserts land in the same second, so created_at is set explicitly
    // to make the ordering deterministic.
    clqSeedLink($this->db, $this->user, (int) $f2->id, (int) $t2->id, 'paypal_funding', 'candidate', '0.900', 'auto', ['signature_hash' => 'all-sig-2']);
    $this->db->connection()->table('chain_links')
        ->whereJsonContains('evidence->signature_hash', 'all-sig-2')
        ->update(['created_at' => '2026-05-20 12:00:00']);
    $this->db->connection()->table('chain_links')
        ->whereJsonContains('evidence->signature_hash', 'all-sig-1')
        ->update(['created_at' => '2026-05-15 12:00:00']);

    $f3 = clqTx($this->user, $this->paypal, $this->run, -800, 'expense', 'Rejected', 'r', '2026-05-09', 'all5', 5);
    $t3 = clqTx($this->user, $this->asn, $this->run, -800, 'transfer_out', 'PayPal SARL', 'p-sarl-3', '2026-05-09', 'all6', 6);
    clqSeedLink($this->db, $this->user, (int) $f3->id, (int) $t3->id, 'paypal_funding', 'rejected', '1.000', 'auto', ['signature_hash' => 'all-sig-rej']);

    // A NULL-endpoint hint must NOT appear (overview is for concrete chains).
    $f4 = clqTx($this->user, $this->paypal, $this->run, -200, 'expense', 'Hint', 'h', '2026-05-12', 'all7', 7);
    clqSeedLink($this->db, $this->user, (int) $f4->id, null, 'ics_bulk_settle', 'candidate', '0.900', 'auto', ['tolerance_used' => 'exceeded', 'signature_hash' => 'all-sig-hint']);

    $rows = $this->query->allChainsForUser($this->user);

    expect($rows)->toHaveCount(2);
    expect($rows[0]->fromCounterparty)->toBe('Newer');
    expect($rows[0]->state)->toBe('candidate');
    expect($rows[0]->fromTransactionId)->toBe((int) $f2->id);
    expect($rows[0]->toTransactionId)->toBe((int) $t2->id);
    expect($rows[1]->fromCounterparty)->toBe('Older');
    expect($rows[1]->state)->toBe('confirmed');
});

it('hasChainForTransaction returns true for either leg of a chain_link', function (): void {
    $paypalExpense = clqTx($this->user, $this->paypal, $this->run, -1500, 'expense', 'Netflix', 'netflix', '2026-05-10', 'hc1', 1);
    $asnTransfer = clqTx($this->user, $this->asn, $this->run, -1500, 'transfer_out', 'PayPal SARL', 'paypal-sarl', '2026-05-10', 'hc2', 2);
    $orphan = clqTx($this->user, $this->paypal, $this->run, -800, 'expense', 'Orphan', 'orphan', '2026-05-11', 'hc3', 3);

    clqSeedLink($this->db, $this->user, (int) $paypalExpense->id, (int) $asnTransfer->id,
        'paypal_funding', 'confirmed', '1.000', 'auto', ['signature_hash' => 'hc-h1']);

    expect($this->query->hasChainForTransaction((int) $paypalExpense->id, $this->user))->toBeTrue();
    expect($this->query->hasChainForTransaction((int) $asnTransfer->id, $this->user))->toBeTrue();
    expect($this->query->hasChainForTransaction((int) $orphan->id, $this->user))->toBeFalse();
});

it('hasChainForTransaction ignores rejected chain_links', function (): void {
    $paypalExpense = clqTx($this->user, $this->paypal, $this->run, -1500, 'expense', 'Rejected', 'r', '2026-05-10', 'hcr1', 1);
    $asnTransfer = clqTx($this->user, $this->asn, $this->run, -1500, 'transfer_out', 'PayPal SARL', 'p-sarl', '2026-05-10', 'hcr2', 2);
    clqSeedLink($this->db, $this->user, (int) $paypalExpense->id, (int) $asnTransfer->id,
        'paypal_funding', 'rejected', '1.000', 'auto', ['signature_hash' => 'hcr-h1']);

    expect($this->query->hasChainForTransaction((int) $paypalExpense->id, $this->user))->toBeFalse();
});

it('forTransaction labels each node Deterministic, Confirmed or Candidate from its link state and confidence', function (): void {
    $paypalExpense = clqTx($this->user, $this->paypal, $this->run, -2500, 'expense', 'Spotify', 'spotify', '2026-05-10', 'b1', 1);
    $asnA = clqTx($this->user, $this->asn, $this->run, 2500, 'transfer_in', 'A', 'a', '2026-05-10', 'b2', 2);
    $asnB = clqTx($this->user, $this->asn, $this->run, 2500, 'transfer_in', 'B', 'b', '2026-05-11', 'b3', 3);
    $asnC = clqTx($this->user, $this->asn, $this->run, 2500, 'transfer_in', 'C', 'c', '2026-05-12', 'b4', 4);

    clqSeedLink($this->db, $this->user, (int) $paypalExpense->id, (int) $asnA->id,
        'paypal_funding', 'confirmed', '1.000', 'auto', ['signature_hash' => 'h1']);
    clqSeedLink($this->db, $this->user, (int) $asnA->id, (int) $asnB->id,
        'paypal_funding', 'confirmed', '0.850', 'rule', ['signature_hash' => 'h2']);
    clqSeedLink($this->db, $this->user, (int) $asnB->id, (int) $asnC->id,
        'paypal_funding', 'candidate', '0.750', 'auto', ['signature_hash' => 'h3']);

    $tree = $this->query->forTransaction((int) $paypalExpense->id, $this->user);

    expect($tree->nodes)->toHaveCount(4);
    expect($tree->nodes[1]->confidenceTier)->toBe(ConfidenceTier::Deterministic);
    expect($tree->nodes[2]->confidenceTier)->toBe(ConfidenceTier::Confirmed);
    expect($tree->nodes[3]->confidenceTier)->toBe(ConfidenceTier::Candidate);
});

it('forTransaction filters rejected chain_links out of the walk', function (): void {
    $paypalExpense = clqTx($this->user, $this->paypal, $this->run, -2500, 'expense', 'Spotify', 'spotify', '2026-05-10', 'd1', 1);
    $asnTransfer = clqTx($this->user, $this->asn, $this->run, 2500, 'transfer_in', 'PayPal', 'paypal', '2026-05-10', 'd2', 2);

    clqSeedLink($this->db, $this->user, (int) $paypalExpense->id, (int) $asnTransfer->id,
        'paypal_funding', 'rejected', '1.000', 'auto', ['signature_hash' => 'h1']);

    $tree = $this->query->forTransaction((int) $paypalExpense->id, $this->user);

    expect($tree->nodes)->toHaveCount(1);
    expect($tree->nodes[0]->kind)->toBe('root');
});

it('forTransaction handles NULL to_transaction_id gracefully', function (): void {
    $asnTransfer = clqTx($this->user, $this->asn, $this->run, 2500, 'transfer_in', 'ASN bulk', 'asn-bulk', '2026-05-10', 'e1', 1);

    clqSeedLink($this->db, $this->user, (int) $asnTransfer->id, null,
        'ics_bulk_settle', 'candidate', '0.700', 'auto', ['signature_hash' => 'h-x']);

    $tree = $this->query->forTransaction((int) $asnTransfer->id, $this->user);

    expect($tree->nodes)->toHaveCount(1);
    expect($tree->nodes[0]->kind)->toBe('root');
});

it('forTransaction raises NotFoundHttpException on cross-user access', function (): void {
    $other = clqUser('clq-other');
    $otherAccount = clqAccount($other, 'clq-other-asn', 'bank', 'NL78OTHR1234567890');
    $otherRun = clqImportRun($other, str_repeat('2', 64));
    $tx = clqTx($other, $otherAccount, $otherRun, 1000, 'expense', 'X', 'x', '2026-05-10', 'f1', 1);

    expect(fn () => $this->query->forTransaction((int) $tx->id, $this->user))
        ->toThrow(NotFoundHttpException::class);
});

it('forTransaction bounds walk depth at 5 levels', function (): void {
    $tx0 = clqTx($this->user, $this->paypal, $this->run, -1000, 'expense', 'L0', 'l0', '2026-05-10', 'g0', 10);
    $tx1 = clqTx($this->user, $this->asn, $this->run, 1000, 'transfer_in', 'L1', 'l1', '2026-05-10', 'g1', 11);
    $tx2 = clqTx($this->user, $this->asn, $this->run, 1000, 'transfer_in', 'L2', 'l2', '2026-05-11', 'g2', 12);
    $tx3 = clqTx($this->user, $this->asn, $this->run, 1000, 'transfer_in', 'L3', 'l3', '2026-05-12', 'g3', 13);
    $tx4 = clqTx($this->user, $this->asn, $this->run, 1000, 'transfer_in', 'L4', 'l4', '2026-05-13', 'g4', 14);
    $tx5 = clqTx($this->user, $this->asn, $this->run, 1000, 'transfer_in', 'L5', 'l5', '2026-05-14', 'g5', 15);
    $tx6 = clqTx($this->user, $this->asn, $this->run, 1000, 'transfer_in', 'L6', 'l6', '2026-05-15', 'g6', 16);

    clqSeedLink($this->db, $this->user, (int) $tx0->id, (int) $tx1->id, 'paypal_funding', 'confirmed', '1.000', 'auto', ['signature_hash' => 'h0']);
    clqSeedLink($this->db, $this->user, (int) $tx1->id, (int) $tx2->id, 'paypal_funding', 'confirmed', '1.000', 'auto', ['signature_hash' => 'h1']);
    clqSeedLink($this->db, $this->user, (int) $tx2->id, (int) $tx3->id, 'paypal_funding', 'confirmed', '1.000', 'auto', ['signature_hash' => 'h2']);
    clqSeedLink($this->db, $this->user, (int) $tx3->id, (int) $tx4->id, 'paypal_funding', 'confirmed', '1.000', 'auto', ['signature_hash' => 'h3']);
    clqSeedLink($this->db, $this->user, (int) $tx4->id, (int) $tx5->id, 'paypal_funding', 'confirmed', '1.000', 'auto', ['signature_hash' => 'h4']);
    clqSeedLink($this->db, $this->user, (int) $tx5->id, (int) $tx6->id, 'paypal_funding', 'confirmed', '1.000', 'auto', ['signature_hash' => 'h5']);

    $tree = $this->query->forTransaction((int) $tx0->id, $this->user);

    // 1 root + 5 levels = 6 nodes; tx6 sits past the depth bound.
    expect(count($tree->nodes))->toBeLessThanOrEqual(6);
    $visitedIds = array_map(fn (ChainTreeNode $n) => $n->transactionId, $tree->nodes);
    expect($visitedIds)->not->toContain((int) $tx6->id);
});

it('openCandidateCount returns count of state=candidate chain_links for the user', function (): void {
    $tx1 = clqTx($this->user, $this->paypal, $this->run, -1000, 'expense', 'A', 'a', '2026-05-10', 'h1', 1);
    $tx2 = clqTx($this->user, $this->asn, $this->run, 1000, 'transfer_in', 'B', 'b', '2026-05-10', 'h2', 2);
    $tx3 = clqTx($this->user, $this->paypal, $this->run, -1500, 'expense', 'C', 'c', '2026-05-11', 'h3', 3);
    $tx4 = clqTx($this->user, $this->asn, $this->run, 1500, 'transfer_in', 'D', 'd', '2026-05-11', 'h4', 4);
    $tx5 = clqTx($this->user, $this->paypal, $this->run, -2000, 'expense', 'E', 'e', '2026-05-12', 'h5', 5);
    $tx6 = clqTx($this->user, $this->asn, $this->run, 2000, 'transfer_in', 'F', 'f', '2026-05-12', 'h6', 6);

    clqSeedLink($this->db, $this->user, (int) $tx1->id, (int) $tx2->id, 'paypal_funding', 'candidate', '0.700', 'auto', ['signature_hash' => 's1']);
    clqSeedLink($this->db, $this->user, (int) $tx3->id, (int) $tx4->id, 'paypal_funding', 'candidate', '0.800', 'auto', ['signature_hash' => 's2']);
    clqSeedLink($this->db, $this->user, (int) $tx5->id, (int) $tx6->id, 'paypal_funding', 'confirmed', '1.000', 'auto', ['signature_hash' => 's3']);

    expect($this->query->openCandidateCount($this->user))->toBe(2);
});

it('hintsForReview returns only NULL-endpoint candidates with parsed evidence lines', function (): void {
    $bank = clqAccount($this->user, 'clq-bank-hint', 'bank', 'NL12ASNBHINT');
    $txHint = clqTx($this->user, $bank, $this->run, -198016, 'transfer_out', 'ICS', 'ics', '2026-04-24', 'hintf', 1);
    clqSeedLink($this->db, $this->user, (int) $txHint->id, null, 'ics_bulk_settle', 'candidate', '0.963', 'auto', [
        'tolerance_used' => 'exceeded',
        'unaccounted_delta_minor' => 7413,
        'covered_count' => 54,
        'statement_id' => 1,
        'signature_hash' => 'sig-h1',
    ]);
    // A concrete candidate must NOT appear in the hints surface.
    $txCandFrom = clqTx($this->user, $this->paypal, $this->run, -1000, 'expense', 'A', 'a', '2026-05-10', 'cand1', 2);
    $txCandTo = clqTx($this->user, $this->asn, $this->run, 1000, 'transfer_in', 'B', 'b', '2026-05-10', 'cand2', 3);
    clqSeedLink($this->db, $this->user, (int) $txCandFrom->id, (int) $txCandTo->id, 'paypal_funding', 'candidate', '0.900', 'auto', ['signature_hash' => 'sig-c1']);

    $hints = $this->query->hintsForReview($this->user);
    expect($hints)->toHaveCount(1);
    expect($hints[0]->kind)->toBe('ics_bulk_settle');
    expect($hints[0]->fromTransactionId)->toBe($txHint->id);
    expect($hints[0]->evidenceLines)->toContain('Tolerance: outside the allowance');
    expect($hints[0]->evidenceLines)->toContain('Covered transactions: 54');
    expect($hints[0]->evidenceLines)->toContain('Card statement #1');
});

it('hintCount returns the count of NULL-endpoint candidates only', function (): void {
    $bank = clqAccount($this->user, 'clq-bank-count', 'bank', 'NL12ASNBCOUNT');
    $tx1 = clqTx($this->user, $bank, $this->run, -100, 'expense', 'A', 'a', '2026-04-24', 'hc1', 1);
    $tx2 = clqTx($this->user, $bank, $this->run, -200, 'expense', 'B', 'b', '2026-04-24', 'hc2', 2);
    clqSeedLink($this->db, $this->user, (int) $tx1->id, null, 'ics_bulk_settle', 'candidate', '0.900', 'auto', ['tolerance_used' => 'exceeded']);
    clqSeedLink($this->db, $this->user, (int) $tx2->id, null, 'funded_by_card_hint', 'candidate', '0.800', 'auto', ['card_last4' => '1234']);

    // A concrete candidate — must NOT be counted as a hint.
    $tx3 = clqTx($this->user, $this->paypal, $this->run, -300, 'expense', 'C', 'c', '2026-05-10', 'hc3', 3);
    $tx4 = clqTx($this->user, $this->asn, $this->run, 300, 'transfer_in', 'D', 'd', '2026-05-10', 'hc4', 4);
    clqSeedLink($this->db, $this->user, (int) $tx3->id, (int) $tx4->id, 'paypal_funding', 'candidate', '0.900', 'auto', ['signature_hash' => 'sig-real']);

    expect($this->query->hintCount($this->user))->toBe(2);
});

it('candidatesForReview excludes hint rows whose to_transaction_id is NULL', function (): void {
    // Actionable candidate — should appear.
    $tx1 = clqTx($this->user, $this->paypal, $this->run, -1000, 'expense', 'A', 'a', '2026-05-10', 'h1', 1);
    $tx2 = clqTx($this->user, $this->asn, $this->run, 1000, 'transfer_in', 'B', 'b', '2026-05-10', 'h2', 2);
    clqSeedLink($this->db, $this->user, (int) $tx1->id, (int) $tx2->id, 'paypal_funding', 'candidate', '0.900', 'auto', ['signature_hash' => 'sig-actionable']);

    // The schema permits a NULL endpoint in candidate state, but Confirm /
    // Reject on such a row would trip the trigger, so it is filtered out.
    $tx3 = clqTx($this->user, $this->paypal, $this->run, -500, 'expense', 'C', 'c', '2026-05-11', 'h3', 3);
    clqSeedLink($this->db, $this->user, (int) $tx3->id, null, 'ics_bulk_settle', 'candidate', '0.950', 'auto', ['tolerance_used' => 'exceeded', 'signature_hash' => 'sig-hint']);

    $rows = $this->query->candidatesForReview($this->user);
    expect($rows)->toHaveCount(1);
    expect($rows[0]->kind)->toBe('paypal_funding');
});

it('candidatesForReview returns ChainLinkRow rows sorted by confidence desc', function (): void {
    $tx1 = clqTx($this->user, $this->paypal, $this->run, -1000, 'expense', 'A', 'a', '2026-05-10', 'i1', 1);
    $tx2 = clqTx($this->user, $this->asn, $this->run, 1000, 'transfer_in', 'B', 'b', '2026-05-10', 'i2', 2);
    $tx3 = clqTx($this->user, $this->paypal, $this->run, -1500, 'expense', 'C', 'c', '2026-05-11', 'i3', 3);
    $tx4 = clqTx($this->user, $this->asn, $this->run, 1500, 'transfer_in', 'D', 'd', '2026-05-11', 'i4', 4);

    clqSeedLink($this->db, $this->user, (int) $tx1->id, (int) $tx2->id, 'paypal_funding', 'candidate', '0.700', 'auto', ['signature_hash' => 's-low']);
    clqSeedLink($this->db, $this->user, (int) $tx3->id, (int) $tx4->id, 'paypal_funding', 'candidate', '0.950', 'auto', ['signature_hash' => 's-high']);

    $rows = $this->query->candidatesForReview($this->user);

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toBeInstanceOf(ChainLinkRow::class);
    expect($rows[0]->confidence)->toBeGreaterThan($rows[1]->confidence);
    expect($rows[0]->state)->toBe('candidate');
    expect($rows[0]->kind)->toBe('paypal_funding');
    expect($rows[0]->confirmsRemaining)->toBe(3);
});

it('candidatesForReview computes confirmsRemaining via same-signature confirmed count', function (): void {
    $signature = 'shared-sig';

    // Two existing confirmed rows with the same signature_hash → 1 confirm remaining.
    for ($i = 1; $i <= 2; $i++) {
        $f = clqTx($this->user, $this->paypal, $this->run, -1000 * $i, 'expense', 'A'.$i, 'a'.$i, '2026-05-0'.$i, 'j'.$i.'a', $i * 2);
        $t = clqTx($this->user, $this->asn, $this->run, 1000 * $i, 'transfer_in', 'B'.$i, 'b'.$i, '2026-05-0'.$i, 'j'.$i.'b', $i * 2 + 1);
        clqSeedLink($this->db, $this->user, (int) $f->id, (int) $t->id, 'paypal_funding', 'confirmed', '1.000', 'auto', ['signature_hash' => $signature]);
    }

    $f3 = clqTx($this->user, $this->paypal, $this->run, -3000, 'expense', 'A3', 'a3', '2026-05-03', 'j3a', 9);
    $t3 = clqTx($this->user, $this->asn, $this->run, 3000, 'transfer_in', 'B3', 'b3', '2026-05-03', 'j3b', 10);
    clqSeedLink($this->db, $this->user, (int) $f3->id, (int) $t3->id, 'paypal_funding', 'candidate', '0.700', 'auto', ['signature_hash' => $signature]);

    $rows = $this->query->candidatesForReview($this->user);
    expect($rows)->toHaveCount(1);
    expect($rows[0]->confirmsRemaining)->toBe(1);
});

it('candidatesForReview is cursor-paginated', function (): void {
    for ($i = 1; $i <= 5; $i++) {
        $f = clqTx($this->user, $this->paypal, $this->run, -1000 * $i, 'expense', 'A'.$i, 'a'.$i, '2026-05-0'.$i, 'k'.$i.'a', $i * 2);
        $t = clqTx($this->user, $this->asn, $this->run, 1000 * $i, 'transfer_in', 'B'.$i, 'b'.$i, '2026-05-0'.$i, 'k'.$i.'b', $i * 2 + 1);
        $confidenceStr = number_format(0.6 + ($i * 0.05), 3, '.', '');
        clqSeedLink($this->db, $this->user, (int) $f->id, (int) $t->id, 'paypal_funding', 'candidate', $confidenceStr, 'auto', ['signature_hash' => 'sig-'.$i]);
    }

    $first = $this->query->candidatesForReview($this->user, limit: 2);
    expect($first)->toHaveCount(2);
    expect($first[0]->confidence)->toBeGreaterThanOrEqual($first[1]->confidence);

    $cursorId = $first[1]->chainLinkId;
    $cursorConfidence = number_format($first[1]->confidence, 3, '.', '');
    $second = $this->query->candidatesForReview($this->user, $cursorId, $cursorConfidence, limit: 2);

    expect($second)->toHaveCount(2);
    $firstIds = array_map(fn (ChainLinkRow $r) => $r->chainLinkId, $first);
    $secondIds = array_map(fn (ChainLinkRow $r) => $r->chainLinkId, $second);
    expect(array_intersect($firstIds, $secondIds))->toBe([]);
});

it('openCandidateCount isolates by user', function (): void {
    $other = clqUser('clq-other-count');
    $otherAccount = clqAccount($other, 'clq-other-cnt', 'paypal', 'OTHER-IBAN');
    $otherRun = clqImportRun($other, str_repeat('3', 64));
    $f = clqTx($other, $otherAccount, $otherRun, -1000, 'expense', 'A', 'a', '2026-05-10', 'oo1', 1);
    $t = clqTx($other, $otherAccount, $otherRun, 1000, 'transfer_in', 'B', 'b', '2026-05-10', 'oo2', 2);
    clqSeedLink($this->db, $other, (int) $f->id, (int) $t->id, 'paypal_funding', 'candidate', '0.800', 'auto', ['signature_hash' => 'x']);

    expect($this->query->openCandidateCount($this->user))->toBe(0);
    expect($this->query->openCandidateCount($other))->toBe(1);
});
