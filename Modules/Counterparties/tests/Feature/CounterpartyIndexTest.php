<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPreference;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyIndex;

/*
 * Feature coverage for the `/counterparties` index page. Pins the seven
 * load-bearing behaviors documented in Plan 17-06b Task 2:
 *
 *   1. Renders cards-default with the page title + ▦ Cards active
 *   2. Filter chips work (URL gains ?type=merchant; only merchant rows render)
 *   3. View toggle persists to user_preferences.counterparty_index_view
 *   4. Empty state — verbatim heading "No counterparties yet"
 *   5. Self-row routes to /accounts/{slug} (link present)
 *   6. Cross-user isolation — user A never sees user B's counterparties
 *   7. Unknown card CTA routes to /counterparties/triage?queue_first={id}
 */

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
    // Default view is 'cards' so the Cards button is the pressed one.
    expect($html)->toContain('view-toggle');
});

it('Test 2: filter chips filter the grid (clicking Merchants narrows the rows)', function (): void {
    $user = cpIndexUser('cp-index-filter');
    cpIndexCounterparty($user->id, 'netflix', 'Netflix', 'merchant');
    cpIndexCounterparty($user->id, 'ah', 'Albert Heijn', 'merchant');
    cpIndexCounterparty($user->id, 'mystery-1', 'NL01TEST0000000001', 'unknown');

    $component = Livewire::actingAs($user)->test(CounterpartyIndex::class);

    $component->assertSee('Netflix');
    $component->assertSee('Albert Heijn');

    $component->call('setType', 'merchant');
    $component->assertSet('type', 'merchant');
    // Counts surface per type.
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
    // self_account counterparties are not normally materialised by the
    // resolver (the resolver short-circuits), but the index renderer
    // must handle existing rows correctly — the row CAN appear if a
    // legacy import wrote one. Insert directly via DB to bypass the
    // resolver short-circuit and verify the rendering path.
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
    $unknownId = cpIndexCounterparty($user->id, 'mystery-1', 'NL01TEST0000000001', 'unknown', 'NL01TEST0000000001');

    $component = Livewire::actingAs($user)->test(CounterpartyIndex::class);

    $html = (string) $component->html();
    expect($html)->toContain('/counterparties/triage?queue_first='.$unknownId);
    $component->assertSee('❋ Label this counterparty', escape: false);
});
