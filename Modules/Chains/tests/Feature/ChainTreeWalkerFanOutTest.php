<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\ChainTreeWalker;
use Modules\Chains\Public\Dto\ChainTree;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

const CTW_FAN_OUT = 40;

function ctwUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function ctwAccount(User $user, string $slug, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ctw '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function ctwRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ctw.csv',
        'sha256' => str_pad($sha, 64, '0'),
        'uploaded_at' => CarbonImmutable::parse('2026-05-01 00:00:00'),
        'status' => 'previewed',
    ]);
}

function ctwTx(User $user, Account $account, ImportRun $run, string $normalized, int $amountMinor, string $type, string $postedAt, int $rowIndex): Transaction
{
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
        'counterparty_name' => 'Name '.$normalized,
        'counterparty_normalized' => $normalized,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => substr(hash('sha256', $normalized.$user->id), 0, 64),
        'fingerprint_version' => 3,
    ]);
}

function ctwLink(DatabaseManager $db, User $user, int $fromId, ?int $toId, string $kind, string $state, string $confidence, string $resolver, string $signature): void
{
    $db->connection()->table('chain_links')->insert([
        'user_id' => $user->id,
        'from_transaction_id' => $fromId,
        'to_transaction_id' => $toId,
        'kind' => $kind,
        'state' => $state,
        'confidence' => $confidence,
        'resolver' => $resolver,
        'evidence' => json_encode(['signature_hash' => $signature, 'tolerance_used' => $toId === null ? 'exceeded' : 'amount_5eur']),
        'created_at' => '2026-05-30 09:00:00',
        'updated_at' => '2026-05-30 09:00:00',
    ]);
}

/**
 * A settlement transfer whose level 1 fans out to CTW_FAN_OUT card expenses —
 * the width MAX_DEPTH does not cap. Confidence varies so the walker's
 * confidence-DESC ordering has something to order, one leg reaches a second
 * level, one leg points at another user's transaction (no node, but it still
 * joins the frontier), and one leg carries the NULL endpoint the walker skips.
 *
 * @return array{user: User, rootId: int, labels: array<int, string>, linkLabels: array<int, string>}
 */
function ctwFixture(DatabaseManager $db): array
{
    $user = ctwUser('ctw-owner');
    $other = ctwUser('ctw-other');

    $bank = ctwAccount($user, 'ctw-asn', 'bank', 'NL57ASNB0123456789');
    $card = ctwAccount($user, 'ctw-ics', 'ics_card', 'ICS-CARD');
    $paypal = ctwAccount($user, 'ctw-paypal', 'paypal', 'PAYPAL');
    $foreign = ctwAccount($other, 'ctw-foreign', 'bank', 'NL99FRGN0000000000');

    $run = ctwRun($user, 'a');
    $otherRun = ctwRun($other, 'b');

    $root = ctwTx($user, $bank, $run, 'ics-bulk-settlement', -400000, 'transfer_out', '2026-05-28', 1);
    $labels = [(int) $root->id => 'root'];
    $linkLabels = [];

    for ($i = 1; $i <= CTW_FAN_OUT; $i++) {
        $expense = ctwTx($user, $card, $run, 'card-expense-'.$i, -10000 - $i, 'expense', '2026-05-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT), $i + 1);
        $labels[(int) $expense->id] = 'expense-'.$i;
        // Two distinct confidence values, so the ordering has ties inside each
        // band and a real ordering between the bands.
        ctwLink($db, $user, (int) $root->id, (int) $expense->id, 'ics_bulk_settle', 'confirmed', $i % 2 === 0 ? '1.000' : '0.900', 'auto', 'sig-'.$i);

        if ($i === 3) {
            $funder = ctwTx($user, $paypal, $run, 'paypal-funder', 10003, 'transfer_in', '2026-05-04', 500);
            $labels[(int) $funder->id] = 'funder';
            ctwLink($db, $user, (int) $expense->id, (int) $funder->id, 'paypal_funding', 'candidate', '0.750', 'auto', 'sig-funder');
        }

        if ($i === 5) {
            $foreignTx = ctwTx($other, $foreign, $otherRun, 'foreign-leg', -999, 'expense', '2026-05-06', 501);
            $labels[(int) $foreignTx->id] = 'foreign';
            ctwLink($db, $user, (int) $expense->id, (int) $foreignTx->id, 'paypal_funding', 'confirmed', '0.500', 'user', 'sig-foreign');
        }
    }

    ctwLink($db, $user, (int) $root->id, null, 'ics_bulk_settle', 'candidate', '0.950', 'auto', 'sig-null');

    // A rejected leg the walker's state filter must keep out of the tree.
    $rejected = ctwTx($user, $card, $run, 'rejected-leg', -777, 'expense', '2026-05-09', 900);
    $labels[(int) $rejected->id] = 'rejected';
    ctwLink($db, $user, (int) $root->id, (int) $rejected->id, 'ics_bulk_settle', 'rejected', '1.000', 'auto', 'sig-rejected');

    /** @var iterable<int, stdClass> $links */
    $links = $db->connection()->table('chain_links')->get(['id', 'evidence']);
    foreach ($links as $link) {
        /** @var array<string, mixed> $evidence */
        $evidence = json_decode((string) $link->evidence, true);
        $linkLabels[(int) $link->id] = is_string($evidence['signature_hash'] ?? null) ? $evidence['signature_hash'] : '';
    }

    return ['user' => $user, 'rootId' => (int) $root->id, 'labels' => $labels, 'linkLabels' => $linkLabels];
}

/**
 * Ids are normalised to fixture labels so the dump compares the tree's shape,
 * order and content rather than autoincrement values.
 *
 * @param  array<int, string>  $labels
 * @param  array<int, string>  $linkLabels
 */
function ctwDump(ChainTree $tree, array $labels, array $linkLabels): string
{
    $rows = [];
    foreach ($tree->nodes as $index => $node) {
        $rows[] = [
            'i' => $index,
            'tx' => $labels[$node->transactionId] ?? 'unlabelled',
            'link' => $node->chainLinkId === null ? null : ($linkLabels[$node->chainLinkId] ?? 'unlabelled'),
            'kind' => $node->kind,
            'tier' => $node->confidenceTier,
            'name' => $node->counterpartyName,
            'minor' => $node->amount->toMinor(),
            'currency' => $node->amount->currency(),
            'account' => $node->accountName,
            'slug' => $node->counterpartySlug,
            'booked' => $node->bookedAt->toDateTimeString(),
        ];
    }

    return (string) json_encode([
        'root' => $labels[$tree->rootTransactionId] ?? 'unlabelled',
        'nodes' => $rows,
    ], JSON_UNESCAPED_SLASHES);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

it('returns the identical tree dump on a fan-out chain', function (): void {
    $fixture = ctwFixture($this->db);

    /** @var ChainTreeWalker $walker */
    $walker = $this->app->make(ChainTreeWalker::class);
    $tree = $walker->walk($fixture['rootId'], $fixture['user']);

    $dump = ctwDump($tree, $fixture['labels'], $fixture['linkLabels']);

    $golden = (string) file_get_contents(__DIR__.'/../fixtures/chain-tree-fan-out.json');

    expect($dump)->toBe(trim($golden));
});

it('walks the fan-out level by level instead of two queries per node', function (): void {
    $fixture = ctwFixture($this->db);

    /** @var list<string> $log */
    $log = [];
    $this->db->connection()->listen(static function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    /** @var ChainTreeWalker $walker */
    $walker = $this->app->make(ChainTreeWalker::class);
    $tree = $walker->walk($fixture['rootId'], $fixture['user']);

    expect($tree->nodes)->toHaveCount(CTW_FAN_OUT + 2);

    $transactionReads = count(array_filter($log, static fn (string $sql): bool => str_contains($sql, 'from "transactions"')));
    $accountReads = count(array_filter($log, static fn (string $sql): bool => str_contains($sql, 'from "accounts"')));

    // One display-row read per level (root, level 1, level 2) and one accounts
    // read for the whole walk — not one of each per node. The per-node shape
    // spent 43 transactions reads and 42 accounts reads on this same fixture.
    expect($transactionReads)->toBe(3);
    expect($accountReads)->toBe(1);
    expect(count($log))->toBe(7);
});
