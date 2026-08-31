<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Chains\Public\Dto\ChainTree;
use Modules\Chains\Public\Dto\ChainTreeNode;
use Modules\Chains\Public\Http\Livewire\ChainDrawer;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// nestChildren() rebuilds every top-level node to hang the fan-out children off
// it, and the rebuild dropped counterpartySlug — so every counterparty link in
// the drawer disappeared the moment a settlement covered more than one charge.
// A single-charge chain never rebuilds, which is why the control passed.

function fanoutLinksAccount(User $user, string $slug, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'fanout '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fanoutLinksTx(User $user, Account $account, ImportRun $run, array $overrides = []): Transaction
{
    static $row = 0;
    $row++;

    return Transaction::query()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'value_date' => '2026-05-10',
        'amount_minor' => -1200,
        'currency' => 'EUR',
        'settled_amount_minor' => -1200,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Fanout merchant',
        'counterparty_normalized' => 'fanout-'.$row,
        'normalization_version' => 3,
        'source_format' => 'ics-pdf',
        'import_run_id' => $run->id,
        'source_row_index' => $row,
        'fingerprint' => hash('sha256', 'fanout-links-'.$row),
        'fingerprint_version' => 3,
    ], $overrides));
}

function fanoutLinksLink(DatabaseManager $db, User $user, int $fromId, int $toId): void
{
    $db->connection()->table('chain_links')->insert([
        'user_id' => $user->id,
        'from_transaction_id' => $fromId,
        'to_transaction_id' => $toId,
        'kind' => 'ics_bulk_settle',
        'state' => 'confirmed',
        'confidence' => '1.000',
        'resolver' => 'auto',
        'evidence' => json_encode(['signature_hash' => 'fanout-sig']),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'fanout-links',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->ics = fanoutLinksAccount($this->user, 'fanout-ics', 'ics_card', 'ICS-CARD');
    $this->asn = fanoutLinksAccount($this->user, 'fanout-asn', 'bank', 'NL57ASNB0123456789');

    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/fanout-links.pdf',
        'sha256' => str_repeat('f', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

it('keeps every counterparty profile link once a settlement fans out', function (): void {
    /** @var Counterparty $icsCounterparty */
    $icsCounterparty = Counterparty::query()->create([
        'user_id' => $this->user->id,
        'type' => 'bank',
        'slug' => 'ics-collection',
        'display_name' => 'ICS Collection',
    ]);
    /** @var Counterparty $chargeCounterparty */
    $chargeCounterparty = Counterparty::query()->create([
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'apple',
        'display_name' => 'Apple',
        'merchant_name' => 'APPLE',
    ]);

    $rootCharge = fanoutLinksTx($this->user, $this->ics, $this->run, ['counterparty_id' => $chargeCounterparty->id]);
    $settlement = fanoutLinksTx($this->user, $this->asn, $this->run, [
        'type' => 'transfer_out',
        'amount_minor' => -84732,
        'settled_amount_minor' => -84732,
        'posted_at' => '2026-05-20',
        'booked_at' => '2026-05-20 12:00:00',
        'counterparty_id' => $icsCounterparty->id,
    ]);
    fanoutLinksLink($this->db, $this->user, (int) $rootCharge->id, (int) $settlement->id);

    // Two children make it a fan-out; one would stay in the flat waterfall.
    $children = [];
    for ($i = 0; $i < 2; $i++) {
        $child = fanoutLinksTx($this->user, $this->ics, $this->run, ['counterparty_id' => $chargeCounterparty->id]);
        $children[] = $child;
        fanoutLinksLink($this->db, $this->user, (int) $settlement->id, (int) $child->id);
    }

    $component = Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', (int) $settlement->id);

    /** @var ChainTree $tree */
    $tree = $component->viewData('tree');

    $slugs = array_map(static fn (ChainTreeNode $node): ?string => $node->counterpartySlug, $tree->nodes);
    expect($slugs)->toContain('ics-collection');

    $withChildren = array_values(array_filter($tree->nodes, static fn (ChainTreeNode $node): bool => $node->children !== []));
    expect($withChildren)->toHaveCount(1);
    foreach ($withChildren[0]->children as $child) {
        expect($child->counterpartySlug)->toBe('apple');
    }

    $component->assertSee(route('counterparties.profile', ['slug' => 'apple']), false);
    $component->assertSee(route('counterparties.profile', ['slug' => 'ics-collection']), false);
});
