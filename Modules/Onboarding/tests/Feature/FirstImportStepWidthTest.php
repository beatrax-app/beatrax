<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectBankStep;
use Modules\Onboarding\Internal\Http\Livewire\Steps\FirstImportStep;

/*
 * Acceptance coverage for WIZ-05 — first-import-step width relaxation.
 *
 * The wizard's default card width is 620px (sketch 002D lock); the
 * first-import step is the single locked exception (UI-SPEC §"Density
 * rules"), relaxing the card to 1120px because the preview table needs
 * the horizontal room.
 *
 * Two assertions:
 *
 *  1. FirstImportStep renders the wiz-card--wide CSS class — the
 *     load-bearing visual signal for the relaxed width.
 *
 *  2. ConnectBankStep (representative of every other connector step)
 *     does NOT render wiz-card--wide. Every other step uses the
 *     default 620px wrapper that the SetupWizard parent provides.
 */

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
