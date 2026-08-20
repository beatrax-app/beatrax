<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SettingsPage;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\LegalLinks;

/*
 * Both stores require the privacy policy to be reachable from inside the app
 * and not only from the store listing — Play's User Data policy asks for "a
 * privacy policy link or text within the app itself", and Apple's 5.1.1(i)
 * asks for the same.
 *
 * The URL is asserted as well as the affordance, because the failure mode is
 * a link that renders and points nowhere useful.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

it('reaches the privacy policy from settings', function (): void {
    Livewire::test(SettingsPage::class)
        ->assertSee('Privacy policy')
        ->assertSee('Read the privacy policy')
        ->assertSee(LegalLinks::PRIVACY_POLICY_URL);
});

it('prints the policy address as text as well as linking it', function (): void {
    // A WebView shell is free to ignore target="_blank", and an unreachable
    // policy is the same rejection as a missing one — so the address is
    // readable and selectable even when nothing opens.
    $rendered = Livewire::test(SettingsPage::class)->html();

    expect(substr_count($rendered, LegalLinks::PRIVACY_POLICY_URL))->toBeGreaterThanOrEqual(2);
    expect($rendered)->toContain('href="'.LegalLinks::PRIVACY_POLICY_URL.'"');
});

it('points at a https address on the published domain', function (): void {
    expect(LegalLinks::PRIVACY_POLICY_URL)->toStartWith('https://beatrax.app/');
});
