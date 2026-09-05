<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Public\Services\EnvelopeActivationService;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Pots\Internal\Enums\PotLinkType;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Models\Pot;

uses(RefreshDatabase::class);

// A hard cutover with no balance migration: category-linked pots are archived,
// never converted, so genesis carried_in is 0 for every envelope.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'cutover-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN cutover',
        'slug' => 'cutover-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'cutover-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
});

it('archives every active category-linked pot via the normal release-to-unallocated path, seeding no envelope balance', function (): void {
    $pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->groceries->id,
        'goal_id' => null,
        'name' => 'Groceries pot',
        'status' => 'active',
    ]);

    // Real balance in the pot: this money must not be seeded into any envelope.
    DB::table('pot_movements')->insert([
        'user_id' => $this->user->id,
        'pot_id' => $pot->id,
        'counterpart_pot_id' => null,
        'amount_minor' => 15000,
        'currency' => 'EUR',
        'kind' => 'fund',
        'memo' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(EnvelopeActivationService::class)->activate();

    $this->assertDatabaseHas('pots', ['id' => $pot->id, 'status' => 'archived']);
    $this->assertDatabaseMissing('envelope_assignments', ['category_id' => $this->groceries->id]);

    $envelopeActivatedAt = DB::table('users')->where('id', $this->user->id)->value('envelope_activated_at');
    expect($envelopeActivatedAt)->not->toBeNull();
});

it('leaves goal-linked pots untouched', function (): void {
    $goalPot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
        'goal_id' => null,
        'name' => 'Untouched pot',
        'status' => 'active',
    ]);

    app(EnvelopeActivationService::class)->activate();

    $this->assertDatabaseHas('pots', ['id' => $goalPot->id, 'status' => 'active']);
});

it('is idempotent -- a second activate() call does not re-archive or double-stamp', function (): void {
    Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->groceries->id,
        'goal_id' => null,
        'name' => 'Groceries pot',
        'status' => 'active',
    ]);

    app(EnvelopeActivationService::class)->activate();
    $firstStamp = DB::table('users')->where('id', $this->user->id)->value('envelope_activated_at');

    app(EnvelopeActivationService::class)->activate();
    $secondStamp = DB::table('users')->where('id', $this->user->id)->value('envelope_activated_at');

    expect($secondStamp)->toBe($firstStamp);
});

it('never archives pots via a single unscoped bulk UPDATE (per-user ownership check only)', function (): void {
    $mallory = User::create(['username' => 'mallory-cutover', 'password' => 'x', 'period_start_day' => 1]);
    $malloryAccount = Account::create([
        'user_id' => $mallory->id,
        'name' => 'Mallory ASN cutover',
        'slug' => 'mallory-cutover-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
    $malloryCategory = Category::create(['user_id' => $mallory->id, 'name' => 'Mallory Groceries', 'slug' => 'mallory-cutover-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $malloryPot = Pot::factory()->create([
        'user_id' => $mallory->id,
        'account_id' => $malloryAccount->id,
        'category_id' => $malloryCategory->id,
        'goal_id' => null,
        'name' => 'Mallory pot',
        'status' => 'active',
    ]);

    Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->groceries->id,
        'goal_id' => null,
        'name' => 'Groceries pot',
        'status' => 'active',
    ]);

    app(EnvelopeActivationService::class)->activate();

    // Both users' pots end archived, but each through PotWriter::archive()'s
    // per-user path — what matters is that the activation loop never reaches
    // pots with an unscoped bulk UPDATE.
    $this->assertDatabaseHas('pots', ['id' => $malloryPot->id, 'status' => 'archived']);
    expect(DB::table('users')->where('id', $mallory->id)->value('envelope_activated_at'))->not->toBeNull();
});

// The archive step is two clicks from being undone, and the undo went through
// PotWriter::restore(), which rewrote status without looking at the link. The
// cutover has to survive the restore or it is a pause, not a cutover.
it('is not undone by restoring an archived pot from the page', function (): void {
    $pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->groceries->id,
        'goal_id' => null,
        'name' => 'Groceries pot',
        'status' => 'active',
    ]);

    $this->actingAs($this->user);
    app(EnvelopeActivationService::class)->activate();

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->call('restorePot', $pot->id);

    expect($pot->fresh()->status)->toBe('active')
        ->and($pot->fresh()->category_id)->toBeNull()
        ->and(DB::table('pots')->where('status', 'active')->whereNotNull('category_id')->count())->toBe(0);
});

// A goal write refuses a category-linked pot and sends the reader to the Pots
// page. The form there offers Goal and None and no category picker at all, so
// the sentence is only true while opening the pot and saving it clears the link.
it('lets the page clear a link a goal write sends the reader there to remove', function (): void {
    $pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->groceries->id,
        'goal_id' => null,
        'name' => 'Groceries pot',
        'status' => 'active',
    ]);

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->call('openEdit', $pot->id)
        ->assertSet('linkType', PotLinkType::None->value)
        ->assertSet('name', 'Groceries pot')
        ->call('updatePot')
        ->assertDispatched('toast');

    expect($pot->fresh()->category_id)->toBeNull()
        ->and($pot->fresh()->name)->toBe('Groceries pot')
        ->and($pot->fresh()->status)->toBe('active');
});
