<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

function ccUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function ccAccount(User $user, string $slug, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'cc '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function ccImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cc.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

function ccTx(
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
    ?int $counterpartyId = null,
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
        'counterparty_id' => $counterpartyId,
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
function ccSeedLink(
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

    $this->user = ccUser('chain-click-through');
    $this->actingAs($this->user);

    $this->paypal = ccAccount($this->user, 'cc-paypal', 'paypal', 'PAYPAL');
    $this->asn = ccAccount($this->user, 'cc-asn', 'asn', 'NL57ASNB0123456789');
    $this->run = ccImportRun($this->user, str_repeat('2', 64));

    /** @var ChainLinkQuery $query */
    $query = $this->app->make(ChainLinkQuery::class);
    $this->query = $query;
});

it('ChainLinkRow carries the from/to counterparty slugs from the JOIN', function (): void {
    /** @var Counterparty $spotifyCp */
    $spotifyCp = Counterparty::create([
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'spotify',
        'display_name' => 'Spotify',
        'merchant_name' => 'SPOTIFY',
    ]);
    /** @var Counterparty $paypalCp */
    $paypalCp = Counterparty::create([
        'user_id' => $this->user->id,
        'type' => 'bank',
        'slug' => 'paypal-sarl',
        'display_name' => 'PayPal SARL',
    ]);

    $paypalExpense = ccTx($this->user, $this->paypal, $this->run, -2500, 'expense', 'Spotify', 'spotify', '2026-05-10', 'sl1', 1, $spotifyCp->id);
    $asnTransfer = ccTx($this->user, $this->asn, $this->run, -2500, 'transfer_out', 'PayPal SARL', 'paypal-sarl', '2026-05-10', 'sl2', 2, $paypalCp->id);

    ccSeedLink(
        $this->db, $this->user, (int) $paypalExpense->id, (int) $asnTransfer->id,
        'paypal_funding', 'confirmed', '1.000', 'auto',
        ['matched_iban' => 'NL57ASNB0123456789', 'signature_hash' => 'sl-h1'],
    );

    $rows = $this->query->allChainsForUser($this->user);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->fromCounterpartySlug)->toBe('spotify');
    expect($rows[0]->toCounterpartySlug)->toBe('paypal-sarl');
});

it('renders the /chains index counterparty names as links routing to counterparties.profile', function (): void {
    /** @var Counterparty $spotifyCp */
    $spotifyCp = Counterparty::create([
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'spotify',
        'display_name' => 'Spotify',
        'merchant_name' => 'SPOTIFY',
    ]);
    /** @var Counterparty $paypalCp */
    $paypalCp = Counterparty::create([
        'user_id' => $this->user->id,
        'type' => 'bank',
        'slug' => 'paypal-sarl',
        'display_name' => 'PayPal SARL',
    ]);

    $paypalExpense = ccTx($this->user, $this->paypal, $this->run, -2500, 'expense', 'Spotify', 'spotify', '2026-05-10', 'idx1', 1, $spotifyCp->id);
    $asnTransfer = ccTx($this->user, $this->asn, $this->run, -2500, 'transfer_out', 'PayPal SARL', 'paypal-sarl', '2026-05-10', 'idx2', 2, $paypalCp->id);
    ccSeedLink(
        $this->db, $this->user, (int) $paypalExpense->id, (int) $asnTransfer->id,
        'paypal_funding', 'confirmed', '1.000', 'auto',
        ['signature_hash' => 'idx-h1'],
    );

    $response = $this->get('/chains');

    $response->assertOk();
    $response->assertSee('href="'.route('counterparties.profile', ['slug' => 'spotify']).'"', false);
    $response->assertSee('href="'.route('counterparties.profile', ['slug' => 'paypal-sarl']).'"', false);
});

it('falls back to plain text on /chains when a leg has no resolved counterparty', function (): void {
    $paypalExpense = ccTx($this->user, $this->paypal, $this->run, -2500, 'expense', 'Unresolved Spotify', 'spotify', '2026-05-10', 'plain1', 1, counterpartyId: null);
    $asnTransfer = ccTx($this->user, $this->asn, $this->run, -2500, 'transfer_out', 'Unresolved PayPal', 'paypal', '2026-05-10', 'plain2', 2, counterpartyId: null);
    ccSeedLink(
        $this->db, $this->user, (int) $paypalExpense->id, (int) $asnTransfer->id,
        'paypal_funding', 'confirmed', '1.000', 'auto',
        ['signature_hash' => 'plain-h1'],
    );

    $response = $this->get('/chains');

    $response->assertOk();
    $response->assertSee('Unresolved Spotify');
    $response->assertSee('data-testid="chains-index-from-counterparty-text-', false);
    $response->assertDontSee('data-testid="chains-index-from-counterparty-link-', false);
});

it('ChainTreeNode carries the resolved counterparty slug for the chain drawer', function (): void {
    /** @var Counterparty $spotifyCp */
    $spotifyCp = Counterparty::create([
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'spotify',
        'display_name' => 'Spotify',
        'merchant_name' => 'SPOTIFY',
    ]);

    $paypalExpense = ccTx($this->user, $this->paypal, $this->run, -2500, 'expense', 'Spotify', 'spotify', '2026-05-10', 'node1', 1, $spotifyCp->id);
    $asnTransfer = ccTx($this->user, $this->asn, $this->run, -2500, 'transfer_out', 'PayPal SARL', 'paypal-sarl', '2026-05-10', 'node2', 2);
    ccSeedLink(
        $this->db, $this->user, (int) $paypalExpense->id, (int) $asnTransfer->id,
        'paypal_funding', 'confirmed', '1.000', 'auto',
        ['signature_hash' => 'node-h1'],
    );

    $tree = $this->query->forTransaction((int) $paypalExpense->id, $this->user);

    expect($tree->nodes)->toHaveCount(2);
    expect($tree->nodes[0]->counterpartySlug)->toBe('spotify');
    expect($tree->nodes[1]->counterpartySlug)->toBeNull();
});

it('ChainLinkHintRow carries the from-counterparty slug', function (): void {
    /** @var Counterparty $cp */
    $cp = Counterparty::create([
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'spotify',
        'display_name' => 'Spotify',
        'merchant_name' => 'SPOTIFY',
    ]);

    $hintFrom = ccTx($this->user, $this->paypal, $this->run, -2500, 'expense', 'Spotify', 'spotify', '2026-05-10', 'hint1', 1, $cp->id);
    ccSeedLink(
        $this->db, $this->user, (int) $hintFrom->id, null,
        'funded_by_card_hint', 'candidate', '0.700', 'fuzzy',
        ['card_last4' => '1234'],
    );

    $rows = $this->query->hintsForReview($this->user);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->fromCounterpartySlug)->toBe('spotify');
});
