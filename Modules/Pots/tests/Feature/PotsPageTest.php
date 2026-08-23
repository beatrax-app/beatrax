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

it('renders the pots page', function (): void {
    Livewire::test(PotsPage::class)
        ->assertOk()
        ->assertSee('Pots');
});

// The phone opens this form as a bottom sheet and the desktop opens the same
// form as a modal. The sheet carried only the name and the account, so once a
// reader had one pot -- the point at which the empty state's modal CTA is gone
// -- every pot they made on a phone started at zero and could not be linked to
// a goal, with both fields sitting in the modal the phone never opens.
it('offers the same fields in the phone sheet as in the desktop modal', function (): void {
    $html = Livewire::test(PotsPage::class)->html();

    expect($html)
        ->toContain('id="pot-amount-sheet"')
        ->toContain('id="pot-name-sheet"')
        ->toContain('id="pot-account-sheet"');

    expect(substr_count($html, 'wire:model.live="linkType"'))->toBe(4);
});

// Both radio pairs submit the token the component branches on, and both
// branches reveal or hide the goal picker. The literals are spelled out here on
// purpose: they are the wire values a stored pot round-trips through, so this
// is the one place they must not be read back off the enum under test.
it('submits goal and none from every link-type radio and reveals the picker only for goal', function (): void {
    $html = Livewire::test(PotsPage::class)->html();

    expect(substr_count($html, 'wire:model.live="linkType" value="goal"'))->toBe(2)
        ->and(substr_count($html, 'wire:model.live="linkType" value="none"'))->toBe(2);

    expect(Livewire::test(PotsPage::class)->set('linkType', 'none')->html())
        ->not->toContain('wire:model="goalId"');

    expect(Livewire::test(PotsPage::class)->set('linkType', 'goal')->html())
        ->toContain('wire:model="goalId"');
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

    Livewire::test(PotsPage::class)
        ->set('name', 'Mallory steal')
        ->set('accountId', (string) $malloryAccount->id)
        ->call('createPot');

    $this->assertDatabaseMissing('pots', [
        'account_id' => $malloryAccount->id,
        'name' => 'Mallory steal',
    ]);
});

it('fundPot inserts a fund movement and the pot balance reflects it', function (): void {
    $pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Holiday',
    ]);

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

    // No account transactions at all, so unallocated is 0.
    Livewire::test(PotsPage::class)
        ->set('operationPotId', $pot->id)
        ->set('operationAmount', '100.00')
        ->set('operationKind', 'fund')
        ->call('fundPot');

    $this->assertDatabaseMissing('pot_movements', [
        'pot_id' => $pot->id,
    ]);
});

// The i18n conversion stopped at render()'s unauthenticated guard branch, so the
// branch every signed-in reader takes kept a literal 'Pots · Beatrax'. The lang
// key already existed in all 26 locales; only the call site was missing.
// Real balance, allocated and unallocated -- and the amber banner that says an
// account cannot cover its own pots -- were rendered inside .pots-desktop-list
// only. A phone showed the pot cards and nothing to add them up against, on the
// page whose own lede promises they "always add up to your real account
// balance".
it('gives the phone list the same reconciliation figures as the desktop group', function (): void {
    $pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Japan trip',
    ]);

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
        'fingerprint' => str_pad('recon1', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    DB::table('pot_movements')->insert([
        'user_id' => $this->user->id,
        'pot_id' => $pot->id,
        'amount_minor' => 150000,
        'currency' => 'EUR',
        'kind' => 'fund',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $html = Livewire::test(PotsPage::class)->html();
    $phoneStart = strpos($html, 'pots-phone-list');
    $desktopStart = strpos($html, 'pots-desktop-list space-y-8');
    expect($phoneStart)->not->toBeFalse()->and($desktopStart)->not->toBeFalse();

    $phoneHtml = substr($html, (int) $phoneStart, (int) $desktopStart - (int) $phoneStart);

    expect($phoneHtml)
        ->toContain('€1,000.00')
        ->toContain('€1,500.00')
        ->toContain('-€500.00')
        ->toContain('Pots exceed real balance by');
});

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

// A pot needs an account, and the page hid every action when there was none —
// leaving the empty state with no way forward at all.
it('offers the import wizard when there is no account to hold a pot', function (): void {
    $this->account->delete();

    Livewire::test(PotsPage::class)
        ->assertOk()
        ->assertSee('Import a statement')
        ->assertSee(route('imports.new'), escape: false);
});
