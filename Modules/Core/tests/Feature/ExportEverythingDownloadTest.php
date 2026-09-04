<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Http\Livewire\ExportEverythingDownload;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'export-everything-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('renders the export form on the SQLite build', function (): void {
    Livewire::test(ExportEverythingDownload::class)
        ->assertOk()
        ->assertSee('Export everything as ZIP');
});

it('rejects a passphrase shorter than 8 characters', function (): void {
    Livewire::test(ExportEverythingDownload::class)
        ->set('passphrase', 'short')
        ->set('confirmPassphrase', 'short')
        ->call('export')
        ->assertSet('error', 'Use a passphrase of at least 8 characters.');
});

it('rejects mismatched passphrases', function (): void {
    Livewire::test(ExportEverythingDownload::class)
        ->set('passphrase', 'a-good-passphrase')
        ->set('confirmPassphrase', 'a-different-one')
        ->call('export')
        ->assertSet('error', 'The two passphrases do not match.');
});

it('is offered to a reader who has never turned Dev Mode on', function (): void {
    expect($this->user->is_developer)->not->toBeTrue();

    Livewire::test(ExportEverythingDownload::class)
        ->assertOk()
        ->assertDontSee('aria-disabled="true"', escape: false);
});
