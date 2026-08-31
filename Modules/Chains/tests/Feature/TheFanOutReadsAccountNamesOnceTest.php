<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Public\Http\Livewire\ChainDrawer;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// ChainTreeWalker reads the reader's account names once for the whole walk
// because "MAX_DEPTH caps how deep the walk goes, nothing caps how wide". The
// drawer's fan-out reconstruction then asked `accounts` again per child, so a
// settlement covering 50 to 300 charges spent one query on each of them.

function fanOutNamesUser(): User
{
    return User::query()->create([
        'username' => 'fanout-names-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = fanOutNamesUser();
    $this->bank = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Fan-out bank',
        'slug' => 'fanout-bank-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
    $this->ics = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Fan-out card',
        'slug' => 'fanout-card-'.bin2hex(random_bytes(3)),
        'kind' => 'ics_card',
        'iban' => 'ICS-FANOUT',
        'default_currency' => 'EUR',
    ]);
    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/fanout.pdf',
        'sha256' => hash('sha256', 'fanout-run-'.$this->user->id),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

function fanOutNamesTx(object $ctx, Account $account, int $amountMinor, string $type, string $name, int $rowIndex): Transaction
{
    return Transaction::query()->create([
        'user_id' => $ctx->user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'value_date' => '2026-05-10',
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => $name,
        'counterparty_normalized' => strtolower($name).'-'.$rowIndex,
        'normalization_version' => 3,
        'source_format' => 'ics-pdf',
        'import_run_id' => $ctx->run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => hash('sha256', 'fanout-'.$ctx->user->id.'-'.$rowIndex),
        'fingerprint_version' => 3,
    ]);
}

it('reads the account names once for the whole fan-out, not once per covered charge', function (): void {
    $settlement = fanOutNamesTx($this, $this->bank, -50000, 'transfer_out', 'ICS Cards', 1);
    for ($i = 1; $i <= 50; $i++) {
        $charge = fanOutNamesTx($this, $this->ics, -1000, 'expense', 'Shop', $i + 1);
        $this->db->connection()->table('chain_links')->insert([
            'id' => ChainLinkInsertHelper::idFor($this->user->id, (int) $settlement->id, (int) $charge->id, 'ics_bulk_settle'),
            'user_id' => $this->user->id,
            'from_transaction_id' => $settlement->id,
            'to_transaction_id' => $charge->id,
            'kind' => 'ics_bulk_settle',
            'state' => 'confirmed',
            'confidence' => '1.000',
            'resolver' => 'auto',
            'evidence' => json_encode(['signature_hash' => 'fanout-sig']),
            'created_at' => CarbonImmutable::now()->toDateTimeString(),
            'updated_at' => CarbonImmutable::now()->toDateTimeString(),
        ]);
    }

    $queries = 0;
    $this->db->connection()->listen(function () use (&$queries): void {
        $queries++;
    });

    $component = Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', (int) $settlement->id);

    expect($queries)->toBeLessThan(15);

    // The map has to stay right, not just cheap: every child still names the
    // card account it sits on.
    $tree = $component->viewData('tree');
    $children = $tree->nodes[0]->children;
    expect($children)->toHaveCount(50);
    foreach ($children as $child) {
        expect($child->accountName)->toBe('Fan-out card');
    }
});
