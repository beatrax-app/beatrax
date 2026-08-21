<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Goals\Models\Goal;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Services\PotWriter;

// Every action turns a writer exception into an inline message rather than an
// error page, so the failure paths are most of what there is to cover.
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/ppa.xml',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->writer = app(PotWriter::class);
});

function ppaCredit(int $userId, int $accountId, int $runId, int $amountMinor): void
{
    static $i = 0;
    $i++;

    Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => "PPA{$i}",
        'counterparty_normalized' => "ppa{$i}",
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i + 7000,
        'fingerprint' => str_pad('ppa'.$i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

function ppaPot(int $userId, int $accountId, string $name = 'Pot', string $status = 'active', ?int $goalId = null): Pot
{
    /** @var Pot $pot */
    $pot = Pot::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'goal_id' => $goalId,
        'category_id' => null,
        'name' => $name,
        'currency' => 'EUR',
        'status' => $status,
    ]);

    return $pot;
}

it('loads the pot into the form when editing opens', function (): void {
    $pot = ppaPot($this->user->id, $this->account->id, 'Buffer');

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->call('openEdit', $pot->id)
        ->assertSet('editPotId', $pot->id)
        ->assertSet('name', 'Buffer')
        ->assertSet('linkType', 'none')
        // Not dispatched: the client already picks sheet or modal by viewport,
        // and announcing it server-side too stacked the modal on the sheet.
        ->assertNotDispatched('modal-show');
});

it('shows the goal picker for a pot that is linked to one', function (): void {
    $goal = Goal::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Holiday',
        'target_minor' => 100000,
        'target_currency' => 'EUR',
        'start_date' => CarbonImmutable::now()->toDateString(),
        'target_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);
    $pot = ppaPot($this->user->id, $this->account->id, 'Holiday pot', 'active', $goal->id);

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->call('openEdit', $pot->id)
        ->assertSet('linkType', 'goal')
        ->assertSet('goalId', (string) $goal->id);
});

it('leaves the form alone when editing a pot that is not the user\'s', function (): void {
    $other = User::create(['username' => 'other', 'password' => 'x', 'period_start_day' => 1]);
    $theirPot = ppaPot($other->id, $this->account->id, 'Theirs');

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->call('openEdit', $theirPot->id)
        ->assertSet('editPotId', 0)
        ->assertNotDispatched('modal-show');
});

it('renames a pot through the form', function (): void {
    $pot = ppaPot($this->user->id, $this->account->id, 'Old');

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->call('openEdit', $pot->id)
        ->set('name', 'New')
        ->call('updatePot')
        ->assertDispatched('modal-close');

    expect($pot->fresh()->name)->toBe('New');
});

it('refuses to save an edit with a blank name and keeps the modal open', function (): void {
    $pot = ppaPot($this->user->id, $this->account->id, 'Old');

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->call('openEdit', $pot->id)
        ->set('name', '   ')
        ->call('updatePot')
        ->assertSet('errorName', 'Enter a name for this pot.')
        ->assertNotDispatched('modal-close');

    expect($pot->fresh()->name)->toBe('Old');
});

it('does nothing when updatePot runs with no pot loaded', function (): void {
    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->set('name', 'Whatever')
        ->call('updatePot')
        ->assertNotDispatched('modal-close');
});

it('surfaces a rejected goal link as an inline error on the edit form', function (): void {
    $goal = Goal::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Holiday',
        'target_minor' => 100000,
        'target_currency' => 'EUR',
        'start_date' => CarbonImmutable::now()->toDateString(),
        'target_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);
    ppaPot($this->user->id, $this->account->id, 'Holds it', 'active', $goal->id);
    $second = ppaPot($this->user->id, $this->account->id, 'Second');

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->call('openEdit', $second->id)
        ->set('linkType', 'goal')
        ->set('goalId', (string) $goal->id)
        ->call('updatePot')
        ->assertSet('errorName', 'This goal already has an active linked pot. Archive it first.')
        ->assertNotDispatched('modal-close');
});

it('clears the form when the edit is cancelled', function (): void {
    $pot = ppaPot($this->user->id, $this->account->id, 'Buffer');

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->call('openEdit', $pot->id)
        ->call('cancel')
        ->assertSet('editPotId', 0)
        ->assertSet('name', '')
        ->assertDispatched('modal-close');
});

it('withdraws from a pot and closes the modal', function (): void {
    ppaCredit($this->user->id, $this->account->id, $this->run->id, 50000);
    $pot = ppaPot($this->user->id, $this->account->id);
    $this->writer->fund($this->user, $pot->id, '100,00');

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->set('operationPotId', $pot->id)
        ->set('operationAmount', '40,00')
        ->call('withdrawPot')
        ->assertDispatched('modal-close');

    expect(DB::table('pot_movements')->where('kind', 'withdraw')->value('amount_minor'))->toBe(-4000);
});

it('tells the user what the pot actually holds when a withdrawal is too large', function (): void {
    ppaCredit($this->user->id, $this->account->id, $this->run->id, 50000);
    $pot = ppaPot($this->user->id, $this->account->id, 'Buffer');
    $this->writer->fund($this->user, $pot->id, '10,00');

    // The message carries the pot's name and formatted balance, which is why the
    // component re-reads the row before calling the writer.
    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->set('operationPotId', $pot->id)
        ->set('operationAmount', '40,00')
        ->call('withdrawPot')
        ->assertSet('errorAmount', 'Amount exceeds balance in Buffer (€10.00 available).')
        ->assertNotDispatched('modal-close');
});

it('surfaces an unparseable withdrawal amount inline', function (): void {
    $pot = ppaPot($this->user->id, $this->account->id);

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->set('operationPotId', $pot->id)
        ->set('operationAmount', 'abc')
        ->call('withdrawPot')
        ->assertSet('errorAmount', 'Invalid or non-positive amount.');
});

it('moves funds between two pots and closes the modal', function (): void {
    ppaCredit($this->user->id, $this->account->id, $this->run->id, 50000);
    $from = ppaPot($this->user->id, $this->account->id, 'From');
    $to = ppaPot($this->user->id, $this->account->id, 'To');
    $this->writer->fund($this->user, $from->id, '100,00');

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->set('operationPotId', $from->id)
        ->set('transferTargetPotId', (string) $to->id)
        ->set('operationAmount', '30,00')
        ->call('movePot')
        ->assertDispatched('modal-close');

    expect(DB::table('pot_movements')->where('kind', 'transfer_in')->value('amount_minor'))->toBe(3000);
});

it('reports the source balance when a move exceeds it', function (): void {
    ppaCredit($this->user->id, $this->account->id, $this->run->id, 50000);
    $from = ppaPot($this->user->id, $this->account->id, 'From');
    $to = ppaPot($this->user->id, $this->account->id, 'To');
    $this->writer->fund($this->user, $from->id, '10,00');

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->set('operationPotId', $from->id)
        ->set('transferTargetPotId', (string) $to->id)
        ->set('operationAmount', '40,00')
        ->call('movePot')
        ->assertSet('errorAmount', 'Amount exceeds balance in From (€10.00 available).');
});

it('turns an unchosen transfer target into an inline error rather than a crash', function (): void {
    ppaCredit($this->user->id, $this->account->id, $this->run->id, 50000);
    $from = ppaPot($this->user->id, $this->account->id, 'From');
    $this->writer->fund($this->user, $from->id, '20,00');

    // The placeholder option is '', which casts to pot id 0 — the writer
    // rejects it and the component has to render that, not a 500.
    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->set('operationPotId', $from->id)
        ->set('transferTargetPotId', '')
        ->set('operationAmount', '5,00')
        ->call('movePot')
        ->assertNotDispatched('modal-close');
});

it('arms and disarms the archive confirmation', function (): void {
    $pot = ppaPot($this->user->id, $this->account->id);

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->call('confirmArchive', $pot->id)
        ->assertSet('archivingPotId', $pot->id)
        ->call('cancelArchive')
        ->assertSet('archivingPotId', 0);
});

it('archives a pot and offers an undo', function (): void {
    $pot = ppaPot($this->user->id, $this->account->id);

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->call('confirmArchive', $pot->id)
        ->call('archivePot', $pot->id)
        ->assertSet('archivingPotId', 0)
        ->assertDispatched('toast');

    expect($pot->fresh()->status)->toBe('archived');
});

it('restores an archived pot', function (): void {
    $pot = ppaPot($this->user->id, $this->account->id, 'Old', 'archived');

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->call('restorePot', $pot->id);

    expect($pot->fresh()->status)->toBe('active');
});
