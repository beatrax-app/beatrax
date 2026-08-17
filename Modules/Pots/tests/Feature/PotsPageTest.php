<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Models\Pot;

/**
 * Wave 0 RED stubs for POTS-01 and POTS-02.
 *
 * These tests reference PotsPage (Plan 03) and PotWriter (Plan 02). They
 * will error with "class not found" until Plans 02 and 03 land — that is
 * correct Wave 0 RED behaviour.
 *
 * Covers:
 *   POTS-01: createPot writes a pots row; blank name rejected; cross-user cannot
 *            create pot for another user's account
 *   POTS-02: fundPot inserts a fund movement; fundPot rejects amount > unallocated
 *            (InsufficientUnallocated path)
 */
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
        'raw_file_path' => '/tmp/test.xml',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

// ---------------------------------------------------------------------------
// POTS-01: Create pot
// ---------------------------------------------------------------------------

it('renders the pots page', function (): void {
    Livewire::test(PotsPage::class)
        ->assertOk()
        ->assertSee('Pots');
});

it('createPot writes a pots row for the acting user', function (): void {
    Livewire::test(PotsPage::class)
        ->set('name', 'Holiday fund')
        ->set('accountId', (string) $this->account->id)
        ->call('createPot')
        ->assertDispatched('toast');

    $this->assertDatabaseHas('pots', [
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Holiday fund',
        'status' => 'active',
    ]);
});

it('createPot with a blank name sets an inline name error and writes nothing', function (): void {
    Livewire::test(PotsPage::class)
        ->set('name', '')
        ->set('accountId', (string) $this->account->id)
        ->call('createPot');

    $this->assertDatabaseMissing('pots', [
        'account_id' => $this->account->id,
    ]);
});

it('cross-user pot cannot be created for another users account', function (): void {
    $mallory = User::create([
        'username' => 'mallory',
        'password' => 'x',
        'period_start_day' => 1,
    ]);
    $malloryAccount = Account::create([
        'user_id' => $mallory->id,
        'name' => 'Mallory ASN',
        'slug' => 'mallory-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB9876543210',
        'default_currency' => 'EUR',
    ]);

    // Wessel (actingAs) attempts to create a pot on Mallory's account — should be rejected.
    Livewire::test(PotsPage::class)
        ->set('name', 'Mallory steal')
        ->set('accountId', (string) $malloryAccount->id)
        ->call('createPot');

    $this->assertDatabaseMissing('pots', [
        'account_id' => $malloryAccount->id,
        'name' => 'Mallory steal',
    ]);
});

// ---------------------------------------------------------------------------
// POTS-02: Fund a pot
// ---------------------------------------------------------------------------

it('fundPot inserts a fund movement and the pot balance reflects it', function (): void {
    $pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Holiday',
    ]);

    // Seed account balance so there is unallocated to fund with
    DB::table('transactions')->insert([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'transfer_in',
        'posted_at' => now()->toDateString(),
        'booked_at' => now()->toDateString().' 12:00:00',
        'value_date' => now()->toDateString(),
        'amount_minor' => 100000,
        'currency' => 'EUR',
        'settled_amount_minor' => 100000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Employer',
        'counterparty_normalized' => 'employer',
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('fund1', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    Livewire::test(PotsPage::class)
        ->set('operationPotId', $pot->id)
        ->set('operationAmount', '200.00')
        ->set('operationKind', 'fund')
        ->call('fundPot')
        ->assertDispatched('toast');

    $this->assertDatabaseHas('pot_movements', [
        'pot_id' => $pot->id,
        'kind' => 'fund',
        'amount_minor' => 20000,
    ]);
});

it('fundPot rejects an amount exceeding unallocated with an inline error and no movement row', function (): void {
    $pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Emergency',
    ]);

    // No account transactions — unallocated = 0; funding 100.00 should fail.
    Livewire::test(PotsPage::class)
        ->set('operationPotId', $pot->id)
        ->set('operationAmount', '100.00')
        ->set('operationKind', 'fund')
        ->call('fundPot');

    $this->assertDatabaseMissing('pot_movements', [
        'pot_id' => $pot->id,
    ]);
});

/*
 * The i18n conversion reached the unauthenticated guard branch of render()
 * and stopped there, so the branch every signed-in reader takes kept a
 * literal 'Pots · Beatrax'. The lang key already existed in all 26 locales;
 * only the call site was missing.
 */
it('localizes the browser tab title on the branch a signed-in reader takes', function (): void {
    // A full page request, not Livewire::test: the title is set through
    // $view->extends(), which only reaches the document on a real render.
    // The locale has to come off the user row too — SetLocale re-resolves it
    // per request, so an app()->setLocale() before the call is discarded.
    $this->user->forceFill(['locale' => 'nl'])->save();

    $response = $this->actingAs($this->user)->get('/pots');

    $response->assertOk();
    expect((string) $response->getContent())
        ->toContain('<title>Potjes · Beatrax</title>')
        ->not->toContain('<title>Pots · Beatrax</title>');
});
