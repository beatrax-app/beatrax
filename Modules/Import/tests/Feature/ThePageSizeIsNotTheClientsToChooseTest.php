<?php

declare(strict_types=1);

use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Modules\Import\Models\MerchantAlias;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'alias-page-size',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    MerchantAlias::create([
        'user_id' => $this->user->id,
        'pattern' => 'PAGE-SIZE-RAW',
        'generalized_pattern' => 'page size',
        'friendly_name' => 'Page size',
    ]);
});

it('refuses a client-chosen page size instead of dividing by it', function (): void {
    $attempt = fn (int $perPage) => Livewire::test(AliasesSettingsPage::class)->set('perPage', $perPage);

    expect(fn () => $attempt(0))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => $attempt(-1))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => $attempt(999999999))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('still paginates the list at its own page size', function (): void {
    Livewire::test(AliasesSettingsPage::class)
        ->assertOk()
        ->assertSet('perPage', 25);
});
