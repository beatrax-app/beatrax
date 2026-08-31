<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Http\Livewire\ChainDrawer;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Locale;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

function cdrUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function cdrAccount(User $user, string $slug, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'cdr '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function cdrImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cdr.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

function cdrTx(
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
function cdrLink(
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

    $this->user = cdrUser('chain-drawer');
    $this->paypal = cdrAccount($this->user, 'cdr-paypal', 'paypal', 'PAYPAL');
    $this->asn = cdrAccount($this->user, 'cdr-asn', 'asn', 'NL57ASNB0123456789');
    $this->ics = cdrAccount($this->user, 'cdr-ics', 'ics_card', 'ICS-CARD');
    $this->run = cdrImportRun($this->user, str_repeat('1', 64));
});

it('opens the drawer and stores the transactionId in the component state', function (): void {
    $tx = cdrTx($this->user, $this->paypal, $this->run, -1500, 'expense', 'Netflix', '2026-05-10', 'a1', 1);

    Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', (int) $tx->id)
        ->assertSet('transactionId', (int) $tx->id)
        ->assertSet('fanoutPage', 0);
});

it('renders the "No funding chain found" empty state when no chain_links exist for the transaction', function (): void {
    $tx = cdrTx($this->user, $this->paypal, $this->run, -1500, 'expense', 'Netflix', '2026-05-10', 'b1', 1);

    Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', (int) $tx->id)
        ->assertSee('No funding chain found');
});

it('renders the "Chain not yet resolved" empty state when transactionId is null (pre-mount)', function (): void {
    Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->assertSee('Chain not yet resolved');
});

it('renders the three-tier confidence chips (Deterministic / Confirmed / Candidate)', function (): void {
    $tx0 = cdrTx($this->user, $this->paypal, $this->run, -2500, 'expense', 'Spotify', '2026-05-10', 'c0', 1);
    $tx1 = cdrTx($this->user, $this->asn, $this->run, 2500, 'transfer_in', 'PayPal', '2026-05-10', 'c1', 2);
    $tx2 = cdrTx($this->user, $this->asn, $this->run, 2500, 'transfer_in', 'A', '2026-05-11', 'c2', 3);
    $tx3 = cdrTx($this->user, $this->asn, $this->run, 2500, 'transfer_in', 'B', '2026-05-12', 'c3', 4);

    cdrLink($this->db, $this->user, (int) $tx0->id, (int) $tx1->id,
        'paypal_funding', 'confirmed', '1.000', 'auto', ['signature_hash' => 'h1']);
    cdrLink($this->db, $this->user, (int) $tx1->id, (int) $tx2->id,
        'paypal_funding', 'confirmed', '0.850', 'rule', ['signature_hash' => 'h2']);
    cdrLink($this->db, $this->user, (int) $tx2->id, (int) $tx3->id,
        'paypal_funding', 'candidate', '0.750', 'auto', ['signature_hash' => 'h3']);

    Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', (int) $tx0->id)
        ->assertSee('Deterministic')
        ->assertSee('Confirmed')
        ->assertSee('Candidate');
});

// The chip printed the tier enum's backing value while the aria-label three
// lines above it went through Lang, so in 25 of the 26 locales the badge and
// the screen reader disagreed about the same chip.
it('renders the confidence chip in the reader s language', function (): void {
    $tx0 = cdrTx($this->user, $this->paypal, $this->run, -2500, 'expense', 'Spotify', '2026-05-10', 'l0', 1);
    $tx1 = cdrTx($this->user, $this->asn, $this->run, 2500, 'transfer_in', 'PayPal', '2026-05-10', 'l1', 2);
    $tx2 = cdrTx($this->user, $this->asn, $this->run, 2500, 'transfer_in', 'A', '2026-05-11', 'l2', 3);
    $tx3 = cdrTx($this->user, $this->asn, $this->run, 2500, 'transfer_in', 'B', '2026-05-12', 'l3', 4);

    cdrLink($this->db, $this->user, (int) $tx0->id, (int) $tx1->id,
        'paypal_funding', 'confirmed', '1.000', 'auto', ['signature_hash' => 'l1']);
    cdrLink($this->db, $this->user, (int) $tx1->id, (int) $tx2->id,
        'paypal_funding', 'confirmed', '0.850', 'rule', ['signature_hash' => 'l2']);
    cdrLink($this->db, $this->user, (int) $tx2->id, (int) $tx3->id,
        'paypal_funding', 'candidate', '0.750', 'auto', ['signature_hash' => 'l3']);

    app()->setLocale(Locale::Nl->value);

    Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', (int) $tx0->id)
        ->assertSee('Deterministisch')
        ->assertSee('Bevestigd')
        ->assertSee('Kandidaat')
        ->assertDontSee('Deterministic')
        ->assertDontSee('Candidate');

    app()->setLocale('en');
});

it('Confirm chip from the drawer promotes a candidate to confirmed', function (): void {
    $tx0 = cdrTx($this->user, $this->paypal, $this->run, -2500, 'expense', 'Spotify', '2026-05-10', 'd0', 1);
    $tx1 = cdrTx($this->user, $this->asn, $this->run, 2500, 'transfer_in', 'PayPal', '2026-05-10', 'd1', 2);

    $linkId = cdrLink($this->db, $this->user, (int) $tx0->id, (int) $tx1->id,
        'paypal_funding', 'candidate', '0.800', 'auto', ['signature_hash' => 'd-sig']);

    Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', (int) $tx0->id)
        ->call('confirm', $linkId);

    /** @var ChainLink $link */
    $link = ChainLink::query()->findOrFail($linkId);
    expect($link->state)->toBe('confirmed');
});

it('Reject chip from the drawer marks a candidate as rejected (per-pair only)', function (): void {
    $tx0 = cdrTx($this->user, $this->paypal, $this->run, -2500, 'expense', 'Spotify', '2026-05-10', 'e0', 1);
    $tx1 = cdrTx($this->user, $this->asn, $this->run, 2500, 'transfer_in', 'PayPal', '2026-05-10', 'e1', 2);

    $linkId = cdrLink($this->db, $this->user, (int) $tx0->id, (int) $tx1->id,
        'paypal_funding', 'candidate', '0.800', 'auto', ['signature_hash' => 'e-sig']);

    Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', (int) $tx0->id)
        ->call('reject', $linkId);

    /** @var ChainLink $link */
    $link = ChainLink::query()->findOrFail($linkId);
    expect($link->state)->toBe('rejected');
});

it('fan-out paginates ICS bulk-settle children at 10 rows per click', function (): void {
    // The ASN settlement leg is the fan-out parent; its children are the
    // covered ICS charges.
    $icsCharge = cdrTx($this->user, $this->ics, $this->run, -1200, 'expense', 'Apple', '2026-05-10', 'f0', 1);
    $asnSettle = cdrTx($this->user, $this->asn, $this->run, -84732, 'transfer_out', 'ICS bulk settle', '2026-05-20', 'f1', 2);
    cdrLink($this->db, $this->user, (int) $icsCharge->id, (int) $asnSettle->id,
        'ics_bulk_settle', 'confirmed', '1.000', 'auto', ['signature_hash' => 'f-sig']);

    // 23 children — two full pages of 10 plus a partial third.
    $children = [];
    for ($i = 1; $i <= 23; $i++) {
        $child = cdrTx($this->user, $this->ics, $this->run, -100 * $i, 'expense', sprintf('Charge%02d', $i), '2026-05-'.str_pad((string) min(28, $i), 2, '0', STR_PAD_LEFT), 'f2'.$i, 10 + $i);
        $children[] = $child;
        cdrLink($this->db, $this->user, (int) $asnSettle->id, (int) $child->id,
            'ics_bulk_settle', 'confirmed', '1.000', 'auto', ['signature_hash' => 'f-sig-'.$i]);
    }

    // Open the drawer scoped to the asnSettle leg directly so the
    // fan-out container renders for that node's children.
    $component = Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', (int) $asnSettle->id);

    $component->assertSee('Show 10 more · 10 of 23');

    $component->call('showMoreFanout')
        ->assertSee('Show 3 more · 20 of 23');

    $component->call('showMoreFanout');
    $component->assertDontSee('Show 10 more')
        ->assertDontSee('Show 3 more');
});

// The box this used to assert claimed a node reached along an ics_bulk_settle
// link covered no ICS charges. The link runs settlement -> charge, so such a
// node is the charge; see ACoveredChargeIsNotItselfAnEmptySettlementTest.
it('renders the settlement and the one charge it covered without a fan-out container', function (): void {
    $asnSettle = cdrTx($this->user, $this->asn, $this->run, -1500, 'transfer_out', 'ICS Cards', '2026-05-20', 'g0', 1);
    $charge = cdrTx($this->user, $this->ics, $this->run, -1500, 'expense', 'Single charge', '2026-05-10', 'g1', 2);
    cdrLink($this->db, $this->user, (int) $asnSettle->id, (int) $charge->id,
        'ics_bulk_settle', 'confirmed', '1.000', 'auto', ['signature_hash' => 'g-sig']);

    Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', (int) $charge->id)
        ->assertSee('Single charge')
        ->assertSee('ICS Cards')
        ->assertDontSee('Covers');
});

it('renders the Flux modal flyout markup (first project use)', function (): void {
    $tx = cdrTx($this->user, $this->paypal, $this->run, -1500, 'expense', 'Netflix', '2026-05-10', 'h1', 1);

    Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', (int) $tx->id)
        ->assertSeeHtml('data-flux-modal');
});

it('TransactionDetail page renders the "View chain" button that dispatches chain-drawer:open', function (): void {
    // The button is gated on hasChainForTransaction(), so seed a real link.
    // An earlier draft rendered it on every row, leading into an empty drawer.
    $tx = cdrTx($this->user, $this->paypal, $this->run, -1500, 'expense', 'Netflix', '2026-05-10', 'i1', 1);
    $funder = cdrTx($this->user, $this->asn, $this->run, -1500, 'transfer_out', 'PayPal SARL', '2026-05-10', 'i2', 2);
    cdrLink($this->db, $this->user, (int) $tx->id, (int) $funder->id,
        'paypal_funding', 'confirmed', '1.000', 'auto', ['signature_hash' => 'i-sig']);

    $response = $this->actingAs($this->user)->get(route('transactions.show', $tx->id));

    $response->assertOk();
    $response->assertSee('View chain', false);
    $response->assertSee('chain-drawer:open', false);
});

it('TransactionDetail page hides the "View chain" button when the transaction has no chain_link', function (): void {
    $tx = cdrTx($this->user, $this->paypal, $this->run, -1500, 'expense', 'Solo', '2026-05-10', 'j1', 1);

    $response = $this->actingAs($this->user)->get(route('transactions.show', $tx->id));

    $response->assertOk();
    $response->assertDontSee('View chain', false);
    $response->assertDontSee('chain-drawer:open', false);
});

it('chain-node.blade.php partial declares explicit @props([\'node\', \'fanoutPage\'])', function (): void {
    $partialPath = base_path('Modules/Chains/Resources/views/livewire/partials/chain-node.blade.php');
    expect(file_exists($partialPath))->toBeTrue();
    $contents = file_get_contents($partialPath);
    expect($contents)->toBeString();
    expect($contents)->toContain("@props(['node', 'fanoutPage'])");
});

it('chain-drawer.blade.php passes $fanoutPage explicitly to the chain-node partial', function (): void {
    $drawerPath = base_path('Modules/Chains/Resources/views/livewire/chain-drawer.blade.php');
    expect(file_exists($drawerPath))->toBeTrue();
    $contents = file_get_contents($drawerPath);
    expect($contents)->toBeString();
    expect($contents)->toContain("'fanoutPage' => \$fanoutPage");
});
