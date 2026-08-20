<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectBankStep;
use Modules\Onboarding\Internal\Http\Livewire\Steps\FirstImportStep;

// Wizard cards are 620px wide. first-import is the one exception, relaxed
// to 1120px via wiz-card--wide because the preview table needs the room.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'first-import-width',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('renders the wiz-card--wide class on the first-import step', function (): void {
    Livewire::test(FirstImportStep::class)
        ->assertSeeHtml('wiz-card--wide');
});

it('does NOT render the wiz-card--wide class on a regular connector step', function (): void {
    Livewire::test(ConnectBankStep::class)
        ->assertDontSeeHtml('wiz-card--wide');
});
