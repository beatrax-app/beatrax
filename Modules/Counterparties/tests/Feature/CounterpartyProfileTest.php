<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyProfile;

/*
 * Feature coverage for `/counterparties/{slug}` — the type-aware
 * profile page. Pins the eight load-bearing behaviors documented in
 * Plan 17-06b Task 2:
 *
 *   8.  Merchant profile renders tab bar + hero
 *   9.  Personal profile shows privacy banner + IBAN dotted by default
 *   10. Personal slug never contains IBAN (URL-routing key is name only)
 *   11. Bank profile renders fee-bar layout
 *   12. Government profile shows tax-year breakdown
 *   13. self_account renders stub redirect (no tabs / no hero)
 *   14. Unknown profile renders Label-this CTA (no Chains tab)
 *   15. Cross-user slug returns 404 (not 403)
 */

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
    $iban = 'NL12RABO0123456789';
    cpProfileRow($user->id, 'alex-jordan', 'Alex Jordan', 'personal', $iban);

    $component = Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'alex-jordan']);

    // Privacy banner verbatim.
    $component->assertSee('🔒 This is a personal contact. IBAN and personal details are hidden by default and never shared in exports.', escape: false);

    // Dotted display present in the HTML; full IBAN is included in
    // the Alpine x-show payload but rendering starts hidden via
    // x-cloak — assert the dotted glyph is there.
    $html = (string) $component->html();
    expect($html)->toContain('····  ····  ····  ····');
    expect($html)->toContain('Show IBAN');
});

it('Test 10: personal slug never contains the IBAN', function (): void {
    $user = cpProfileUser('cp-profile-personal-slug');
    $iban = 'NL12RABO0123456789';
    cpProfileRow($user->id, 'alex-jordan', 'Alex Jordan', 'personal', $iban);

    $component = Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'alex-jordan']);

    $html = (string) $component->html();
    // Page title / breadcrumbs / URL would echo the slug; verify that
    // the slug itself is the kebab-cased name and the IBAN is NOT
    // anywhere outside the Alpine reveal payload's hidden span.
    expect($component->get('slug'))->toBe('alex-jordan');
    expect($component->get('slug'))->not->toContain($iban);
    // Personal IBAN privacy: the page title must read the display name,
    // not the IBAN; the slug column shipped to the URL is also name-only.
    expect($html)->toContain('Alex Jordan');
});

it('Test 11: bank profile renders the fee-bar layout', function (): void {
    $user = cpProfileUser('cp-profile-bank');
    cpProfileRow($user->id, 'ics-fee', 'ICS Bank Fees', 'bank');

    $component = Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'ics-fee']);

    $component->assertSee('Bank fees by category');
    // Right-of-tab-bar note for bank type. Blade escapes the
    // apostrophe to `&#039;` when echoing $tabNote, so the
    // default `escape: true` makes PHPUnit compare the encoded
    // expected string against the encoded rendering.
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
    // The tab bar must NOT render for self_account — assert the
    // wire:click switchTab action (rendered on every tab button) is
    // absent. The body-only render skips the nav element entirely.
    $html = (string) $component->html();
    expect($html)->not->toContain("switchTab('overview')");
});

it('Test 14: unknown renders fallback Label CTA (no Chains tab)', function (): void {
    $user = cpProfileUser('cp-profile-unknown');
    cpProfileRow($user->id, 'mystery-iban-1', 'NL01TEST0000000001', 'unknown', 'NL01TEST0000000001');

    $component = Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'mystery-iban-1']);

    $component->assertSee('Overview');
    $component->assertSee('Transactions');
    $component->assertSee('Aliases');
    // Chains tab is intentionally absent on unknown.
    $component->assertDontSee('Chains');
    $component->assertSee('Label this counterparty');
});

it('Test 15: cross-user slug returns 404 (not 403)', function (): void {
    $userA = cpProfileUser('cp-profile-iso-a');
    $userB = cpProfileUser('cp-profile-iso-b');
    cpProfileRow($userB->id, 'b-private', 'B Private Merchant', 'merchant');

    // Hitting the actual route surfaces the framework's 404 response —
    // the NotFoundHttpException thrown inside mount() bubbles to the
    // route resolver. The status MUST be 404 (not 403) so no signal is
    // emitted that the slug exists in another user's namespace.
    $response = $this->actingAs($userA)
        ->get(route('counterparties.profile', ['slug' => 'b-private']));

    $response->assertStatus(404);
});
