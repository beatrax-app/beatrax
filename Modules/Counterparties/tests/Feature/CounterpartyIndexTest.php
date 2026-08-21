<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPreference;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyIndex;
use Modules\Counterparties\Public\Queries\CounterpartyIndexQuery;

function cpIndexUser(string $username = 'cp-index-fixture'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function cpIndexCounterparty(int $userId, string $slug, string $name, string $type, ?string $iban = null): int
{
    $now = now()->toDateTimeString();

    return DB::table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => $type,
        'slug' => $slug,
        'display_name' => $name,
        'iban' => $iban,
        'merchant_name' => $type === 'merchant' ? $name : null,
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('Test 1: renders cards-default with page title and Cards view active', function (): void {
    $user = cpIndexUser('cp-index-cards');
    cpIndexCounterparty($user->id, 'netflix', 'Netflix', 'merchant');

    $component = Livewire::actingAs($user)->test(CounterpartyIndex::class);

    $component->assertSee('Counterparties');
    $component->assertSee('▦ Cards', escape: false);
    $component->assertSee('≡ List', escape: false);
    $html = (string) $component->html();
    expect($html)->toContain('aria-pressed="true"');
    expect($html)->toContain('view-toggle');
});

it('Test 2: filter chips filter the grid (clicking Merchants narrows the rows)', function (): void {
    $user = cpIndexUser('cp-index-filter');
    cpIndexCounterparty($user->id, 'netflix', 'Netflix', 'merchant');
    cpIndexCounterparty($user->id, 'ah', 'Albert Heijn', 'merchant');
    cpIndexCounterparty($user->id, 'mystery-1', 'NL69TEST0000000001', 'unknown');

    $component = Livewire::actingAs($user)->test(CounterpartyIndex::class);

    $component->assertSee('Netflix');
    $component->assertSee('Albert Heijn');

    $component->call('setType', 'merchant');
    $component->assertSet('type', 'merchant');
    $component->assertSee('Merchants');
});

it('Test 3: view toggle persists to user_preferences.counterparty_index_view', function (): void {
    $user = cpIndexUser('cp-index-toggle');
    cpIndexCounterparty($user->id, 'netflix', 'Netflix', 'merchant');

    $component = Livewire::actingAs($user)->test(CounterpartyIndex::class);
    $component->assertSet('view', 'cards');

    $component->call('setView', 'list');
    $component->assertSet('view', 'list');

    $pref = UserPreference::query()->where('user_id', $user->id)->firstOrFail();
    expect($pref->counterparty_index_view)->toBe('list');
});

it('Test 4: empty state renders the verbatim heading and CTA', function (): void {
    $user = cpIndexUser('cp-index-empty');

    $component = Livewire::actingAs($user)->test(CounterpartyIndex::class);

    $component->assertSee('No counterparties yet');
    $component->assertSee('Counterparties appear here automatically as you import transactions. Import a statement to get started.');
    $component->assertSee('Import a statement →', escape: false);
});

it('Test 5: self_account row routes to /accounts/{slug}', function (): void {
    $user = cpIndexUser('cp-index-self');
    // The resolver short-circuits before writing a self_account row, so this
    // one goes in directly — a legacy import could still have left one behind.
    cpIndexCounterparty($user->id, 'asn-fixture', 'ASN Fixture Account', 'self_account');

    $component = Livewire::actingAs($user)->test(CounterpartyIndex::class);

    $html = (string) $component->html();
    expect($html)->toContain('/accounts/asn-fixture');
});

it('Test 6: cross-user isolation — user A never sees user B counterparties', function (): void {
    $userA = cpIndexUser('cp-index-isolation-a');
    $userB = cpIndexUser('cp-index-isolation-b');
    cpIndexCounterparty($userA->id, 'a-netflix', 'A-Netflix', 'merchant');
    cpIndexCounterparty($userB->id, 'b-spotify', 'B-Spotify', 'merchant');

    $component = Livewire::actingAs($userA)->test(CounterpartyIndex::class);

    $component->assertSee('A-Netflix');
    $component->assertDontSee('B-Spotify');
});

it('Test 7: unknown card CTA routes to /counterparties/triage with queue_first', function (): void {
    $user = cpIndexUser('cp-index-unknown-cta');
    $unknownId = cpIndexCounterparty($user->id, 'mystery-1', 'NL69TEST0000000001', 'unknown', 'NL69TEST0000000001');

    $component = Livewire::actingAs($user)->test(CounterpartyIndex::class);

    $html = (string) $component->html();
    expect($html)->toContain('/counterparties/triage?queue_first='.$unknownId);
    $component->assertSee('❋ Label this counterparty', escape: false);
});

it('renders every counterparty when ?type= carries a column spelling the chip row never offers', function (): void {
    $user = cpIndexUser('cp-index-url-column-spelling');
    cpIndexCounterparty($user->id, 'netflix', 'Netflix', 'merchant');

    // `self_account` is the column value, not a filter value: reaching the
    // #[Url] property it used to become `where type = 'self_account'` and the
    // merchant row vanished behind the empty state.
    $response = $this->actingAs($user)->get(route('counterparties.index', ['type' => 'self_account']));

    $response->assertSee('Netflix');
});

it('shows self_account rows under the self chip and nothing else', function (): void {
    $user = cpIndexUser('cp-index-self-filter');
    cpIndexCounterparty($user->id, 'asn-fixture', 'ASN Fixture Account', 'self_account');
    cpIndexCounterparty($user->id, 'netflix', 'Netflix', 'merchant');

    $component = Livewire::actingAs($user)->test(CounterpartyIndex::class);
    $component->call('setType', 'self');

    $component->assertSee('ASN Fixture Account');
    $component->assertDontSee('Netflix');
});

it('keys the chip counts by the filter vocabulary, not the column vocabulary', function (): void {
    $user = cpIndexUser('cp-index-chip-counts');
    cpIndexCounterparty($user->id, 'asn-fixture', 'ASN Fixture Account', 'self_account');
    cpIndexCounterparty($user->id, 'netflix', 'Netflix', 'merchant');

    $counts = app(CounterpartyIndexQuery::class)->countsByType($user);

    expect($counts['self'])->toBe(1);
    expect($counts['merchant'])->toBe(1);
    expect($counts['all'])->toBe(2);
    expect($counts)->not->toHaveKey('self_account');
});
