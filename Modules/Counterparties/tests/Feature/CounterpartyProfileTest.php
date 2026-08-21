<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyProfile;

function cpProfileUser(string $username = 'cp-profile-fixture'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function cpProfileRow(int $userId, string $slug, string $name, string $type, ?string $iban = null): int
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

it('Test 8: merchant profile renders the merchant tab bar and hero', function (): void {
    $user = cpProfileUser('cp-profile-merchant');
    cpProfileRow($user->id, 'netflix', 'Netflix', 'merchant');

    $component = Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'netflix']);

    $component->assertSee('Netflix');
    $component->assertSee('Overview');
    $component->assertSee('Transactions');
    $component->assertSee('Chains');
    $component->assertSee('Aliases');
    $component->assertSee('12-month total');
});

it('Test 9: personal profile shows privacy banner + IBAN hidden by default', function (): void {
    $user = cpProfileUser('cp-profile-personal');
    $iban = 'NL44RABO0123456789';
    cpProfileRow($user->id, 'alex-jordan', 'Alex Jordan', 'personal', $iban);

    $component = Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'alex-jordan']);

    $component->assertSee('🔒 This is a personal contact. IBAN and personal details are hidden by default and never shared in exports.', escape: false);

    // The full IBAN does ride in the Alpine x-show payload, so the dotted
    // glyph is what proves the render starts hidden behind x-cloak.
    $html = (string) $component->html();
    expect($html)->toContain('····  ····  ····  ····');
    expect($html)->toContain('Show IBAN');
});

it('Test 10: personal slug never contains the IBAN', function (): void {
    $user = cpProfileUser('cp-profile-personal-slug');
    $iban = 'NL44RABO0123456789';
    cpProfileRow($user->id, 'alex-jordan', 'Alex Jordan', 'personal', $iban);

    $component = Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'alex-jordan']);

    $html = (string) $component->html();
    expect($component->get('slug'))->toBe('alex-jordan');
    expect($component->get('slug'))->not->toContain($iban);
    // The slug reaches the URL and the page title, so both have to read the
    // display name.
    expect($html)->toContain('Alex Jordan');
});

it('Test 11: bank profile renders the fee-bar layout', function (): void {
    $user = cpProfileUser('cp-profile-bank');
    cpProfileRow($user->id, 'ics-fee', 'ICS Bank Fees', 'bank');

    $component = Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'ics-fee']);

    $component->assertSee('Bank fees by category');
    // Blade encodes the apostrophe, and the default `escape: true` encodes the
    // expectation to match — hence no `escape: false` here.
    $component->assertSee("— bank-fee counterparty doesn't generate funding chains");
});

it('Test 12: government profile renders tax-year breakdown intro', function (): void {
    $user = cpProfileUser('cp-profile-government');
    cpProfileRow($user->id, 'belastingdienst', 'Belastingdienst', 'government');

    $component = Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'belastingdienst']);

    $component->assertSee('Tax years');
    $component->assertSee('Annual breakdown across all years with activity. Current year is emphasized.');
    $component->assertSee('— no funding chains for government counterparties', escape: false);
});

it('Test 13: self_account renders the stub redirect (no tabs / no hero)', function (): void {
    $user = cpProfileUser('cp-profile-self');
    cpProfileRow($user->id, 'asn-fixture', 'ASN Fixture Account', 'self_account');

    $component = Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'asn-fixture']);

    $component->assertSee("This isn't really a counterparty", escape: false);
    $component->assertSee('Open ASN Fixture Account account view →', escape: false);
    // Every tab button carries this wire:click, so its absence is the proof
    // that the nav element was skipped entirely.
    $html = (string) $component->html();
    expect($html)->not->toContain("switchTab('overview')");
});

it('Test 14: unknown renders fallback Label CTA (no Chains tab)', function (): void {
    $user = cpProfileUser('cp-profile-unknown');
    cpProfileRow($user->id, 'mystery-iban-1', 'NL69TEST0000000001', 'unknown', 'NL69TEST0000000001');

    $component = Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'mystery-iban-1']);

    $component->assertSee('Overview');
    $component->assertSee('Transactions');
    $component->assertSee('Aliases');
    $component->assertDontSee('Chains');
    $component->assertSee('Label this counterparty');
});

it('Test 15: cross-user slug returns 404 (not 403)', function (): void {
    $userA = cpProfileUser('cp-profile-iso-a');
    $userB = cpProfileUser('cp-profile-iso-b');
    cpProfileRow($userB->id, 'b-private', 'B Private Merchant', 'merchant');

    // 404 rather than 403: a 403 would confirm the slug exists in someone
    // else's namespace. The route is hit for real so the exception mount()
    // throws goes through the framework's own handler.
    $response = $this->actingAs($userA)
        ->get(route('counterparties.profile', ['slug' => 'b-private']));

    $response->assertStatus(404);
});

it('renders the support-resource card with a cancel link on a known merchant', function (): void {
    $user = cpProfileUser('cp-support-merchant');
    cpProfileRow($user->id, 'kpn-support', 'KPN', 'merchant');

    Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'kpn-support'])
        ->assertSee('Support & cancelling')
        ->assertSee('Cancel')
        ->assertSee('opzegformulier');
});

it('renders the getting-help card with a phone number on a known government agency', function (): void {
    $user = cpProfileUser('cp-support-gov');
    cpProfileRow($user->id, 'belastingdienst-support', 'Belastingdienst', 'government');

    Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'belastingdienst-support'])
        ->assertSee('Getting help')
        ->assertSee('0800-0543');
});

it('shows no support card for a counterparty absent from the corpus', function (): void {
    $user = cpProfileUser('cp-support-none');
    cpProfileRow($user->id, 'nameless-shop', 'Totally Nameless Shop', 'merchant');

    Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'nameless-shop'])
        ->assertDontSee('Support & cancelling');
});
