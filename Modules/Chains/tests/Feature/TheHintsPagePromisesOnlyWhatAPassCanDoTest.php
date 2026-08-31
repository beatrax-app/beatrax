<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Internal\Http\Livewire\ChainHintsQueue;
use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// A receipt hint's `to_transaction_id` is NULL and nothing writes that column
// outside an INSERT, so no pass can bind one: a funded_by_card hint leaves the
// queue only when the reader dismisses it. The page promised every hint either
// resolves itself on the next chain pass — a way out with no code behind it.

function hintsPromiseUser(): User
{
    return User::query()->create([
        'username' => 'hints-promise-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = hintsPromiseUser();

    $card = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Hints promise card',
        'slug' => 'hints-promise-card-'.bin2hex(random_bytes(3)),
        'kind' => 'ics_card',
        'iban' => 'ICS-HINTS-PROMISE',
        'default_currency' => 'EUR',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/hints-promise.pdf',
        'sha256' => hash('sha256', 'hints-promise-'.$this->user->id),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    $charge = Transaction::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $card->id,
        'type' => 'expense',
        'posted_at' => '2026-05-12',
        'booked_at' => '2026-05-12 12:00:00',
        'value_date' => '2026-05-12',
        'amount_minor' => -2599,
        'currency' => 'EUR',
        'settled_amount_minor' => -2599,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Coolblue',
        'counterparty_normalized' => 'coolblue',
        'normalization_version' => 3,
        'source_format' => 'ics-pdf',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'hints-promise-tx-'.$this->user->id),
        'fingerprint_version' => 3,
    ]);

    // The row CreateChainLinkFromHint writes for a receipt that surfaced a card
    // last-four, spelled exactly as that listener spells it.
    $this->db->connection()->table('chain_links')->insert([
        'id' => ChainLinkInsertHelper::idFor($this->user->id, (int) $charge->id, null, 'funded_by_card_hint'),
        'user_id' => $this->user->id,
        'from_transaction_id' => $charge->id,
        'to_transaction_id' => null,
        'kind' => 'funded_by_card_hint',
        'state' => 'candidate',
        'confidence' => '0.500',
        'resolver' => 'auto',
        'evidence' => json_encode(['card_last4' => '4321', 'source_evidence' => []]),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    /** @var ChainLinkQuery $query */
    $query = $this->app->make(ChainLinkQuery::class);
    $this->query = $query;
});

it('leaves a receipt hint exactly where it was after a full resolver pass', function (): void {
    ResolveChainLinksJob::dispatchSync($this->user->id);

    expect($this->query->hintCount($this->user))->toBe(1);
});

it('does not promise the reader a pass that will resolve it', function (): void {
    Livewire::actingAs($this->user)
        ->test(ChainHintsQueue::class)
        ->assertSee('Card ending in 4321')
        ->assertDontSee('Each hint either resolves itself on the next chain pass');
});
