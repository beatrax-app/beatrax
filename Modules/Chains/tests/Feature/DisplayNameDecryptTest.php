<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Chains\Internal\Http\Livewire\ChainDrawer;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(EnablesEncryptionForUser::class);

/*
 * 14.1-10 Task 3 (D-06 fold-in, per 14.1-AUDIT.md Cluster 1) — under an
 * encrypted user, `transactions.counterparty_name` is ciphertext at
 * rest. None of ChainLinkQuery::makeNode()/makeChainLinkRow()'s
 * from/to lookups, ChainDrawer's fan-out child hydration, or
 * UncategorizedTriageQuery::mapRow() do a SQL predicate/ORDER BY on
 * this column (every WHERE clause is scoped by id/user_id), so the
 * D-09 predicate guard cannot catch this class of leak — only a
 * decrypt-for-display test proves it.
 */

function cddUser(): User
{
    return User::query()->create([
        'username' => 'cdd-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function cddAccount(User $user, string $slug, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'cdd '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function cddImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cdd.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

/**
 * Inserts a transaction row whose counterparty_name (and optionally
 * description) is ciphertext at rest — mirrors what a direct-write
 * import would have produced for an already-encrypted user.
 */
function cddEncryptedTx(
    DatabaseManager $db,
    Session $session,
    User $user,
    Account $account,
    ImportRun $run,
    int $amountMinor,
    string $type,
    string $counterpartyName,
    string $postedAt,
    string $fingerprintSeed,
    int $rowIndex = 1,
    ?string $description = null,
): int {
    /** @var SensitiveColumnCodec $codec */
    $codec = Container::getInstance()->make(SensitiveColumnCodec::class);
    $encryptedCounterparty = $codec->encryptValue('transactions', 'counterparty_name', $counterpartyName, (int) $user->id, $session);
    expect($encryptedCounterparty)->not->toBe($counterpartyName);

    $encryptedDescription = $description === null
        ? null
        : $codec->encryptValue('transactions', 'description', $description, (int) $user->id, $session);

    return (int) $db->connection()->table('transactions')->insertGetId([
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
        'counterparty_name' => $encryptedCounterparty,
        'description' => $encryptedDescription,
        'counterparty_normalized' => 'cdd-fixture',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad($fingerprintSeed, 64, 'c', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function cddSeedLink(DatabaseManager $db, User $user, int $fromId, ?int $toId, string $kind, string $state, string $confidence, string $resolver): int
{
    $db->connection()->table('chain_links')->insert([
        'user_id' => $user->id,
        'from_transaction_id' => $fromId,
        'to_transaction_id' => $toId,
        'kind' => $kind,
        'state' => $state,
        'confidence' => $confidence,
        'resolver' => $resolver,
        'evidence' => json_encode(['signature_hash' => 'cdd-'.bin2hex(random_bytes(4))]),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return (int) $db->connection()->table('chain_links')->max('id');
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = cddUser();
    $this->session = $this->enablesEncryptionForUser($this->user);
    $this->paypal = cddAccount($this->user, 'cdd-paypal', 'paypal', 'PAYPAL');
    $this->asn = cddAccount($this->user, 'cdd-asn', 'asn', 'NL57ASNB0123456789');
    $this->run = cddImportRun($this->user, str_repeat('9', 64));
});

it('ChainLinkQuery::forTransaction decrypts counterparty_name in tree nodes (makeNode)', function (): void {
    $expense = cddEncryptedTx($this->db, $this->session, $this->user, $this->paypal, $this->run, -2500, 'expense', 'Spotify', '2026-05-10', 'cdd-a1', 1);
    $funder = cddEncryptedTx($this->db, $this->session, $this->user, $this->asn, $this->run, 2500, 'transfer_in', 'PayPal', '2026-05-10', 'cdd-a2', 2);
    cddSeedLink($this->db, $this->user, $expense, $funder, 'paypal_funding', 'confirmed', '1.000', 'auto');

    /** @var ChainLinkQuery $query */
    $query = $this->app->make(ChainLinkQuery::class);
    $tree = $query->forTransaction($expense, $this->user);

    expect($tree->nodes)->toHaveCount(2);
    expect($tree->nodes[0]->counterpartyName)->toBe('Spotify');
    expect($tree->nodes[1]->counterpartyName)->toBe('PayPal');
});

it('ChainLinkQuery::allChainsForUser decrypts from/to counterparty_name in link rows (makeChainLinkRow)', function (): void {
    $expense = cddEncryptedTx($this->db, $this->session, $this->user, $this->paypal, $this->run, -1500, 'expense', 'Zebra Vendor', '2026-05-12', 'cdd-b1', 1);
    $funder = cddEncryptedTx($this->db, $this->session, $this->user, $this->asn, $this->run, -1500, 'transfer_out', 'PayPal SARL', '2026-05-12', 'cdd-b2', 2);
    cddSeedLink($this->db, $this->user, $expense, $funder, 'paypal_funding', 'confirmed', '1.000', 'auto');

    /** @var ChainLinkQuery $query */
    $query = $this->app->make(ChainLinkQuery::class);
    $rows = $query->allChainsForUser($this->user);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->fromCounterparty)->toBe('Zebra Vendor');
    expect($rows[0]->toCounterparty)->toBe('PayPal SARL');
});

it('ChainDrawer fan-out children decrypt counterparty_name (makeChildNode)', function (): void {
    $root = cddEncryptedTx($this->db, $this->session, $this->user, $this->asn, $this->run, -10000, 'transfer_out', 'ICS Card', '2026-05-15', 'cdd-c1', 1);
    $child1 = cddEncryptedTx($this->db, $this->session, $this->user, $this->paypal, $this->run, -3000, 'expense', 'Netflix', '2026-05-14', 'cdd-c2', 2);
    $child2 = cddEncryptedTx($this->db, $this->session, $this->user, $this->paypal, $this->run, -2000, 'expense', 'Spotify Premium', '2026-05-14', 'cdd-c3', 3);
    cddSeedLink($this->db, $this->user, $root, $child1, 'ics_bulk_settle', 'confirmed', '1.000', 'auto');
    cddSeedLink($this->db, $this->user, $root, $child2, 'ics_bulk_settle', 'confirmed', '1.000', 'auto');

    $html = Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', $root)
        ->html();

    expect($html)->toContain('Netflix');
    expect($html)->toContain('Spotify Premium');
});
