<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Community\Internal\Http\Livewire\HelpOthersTriageButton;
use Modules\Core\Models\User;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'triage-cta-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('renders the CTA copy when offerToContribute is true (default)', function (): void {
    $component = Livewire::test(HelpOthersTriageButton::class, ['raw' => 'CRYPTIC X']);

    expect($component->html())->toContain('Help others identify this');
});

it('does not render the CTA copy when offerToContribute is false', function (): void {
    $this->user->community_settings = ['offerToContribute' => false];
    $this->user->save();

    $component = Livewire::test(HelpOthersTriageButton::class, ['raw' => 'CRYPTIC X']);

    expect($component->html())->not->toContain('Help others identify this');
});

it('dispatches suggest-mapping:open on click when visible', function (): void {
    Livewire::test(HelpOthersTriageButton::class, ['raw' => 'CRYPTIC PAY 123'])
        ->call('clicked')
        ->assertDispatched('suggest-mapping:open');
});

it('does not dispatch suggest-mapping:open on click when hidden by Toggle 2', function (): void {
    $this->user->community_settings = ['offerToContribute' => false];
    $this->user->save();

    Livewire::test(HelpOthersTriageButton::class, ['raw' => 'CRYPTIC PAY 123'])
        ->call('clicked')
        ->assertNotDispatched('suggest-mapping:open');
});
