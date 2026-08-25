<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\MobileRestoreFromBackup;

function restoreScreenUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('offers the route back from the welcome screen, which previously offered none', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Mobile/Resources/views/livewire/mobile-welcome-screen.blade.php'),
    );

    expect($blade)->toContain("route('mobile.restore')");
    expect($blade)->toContain('mobile::welcome.restore');
});

it('shows the screen on a fresh install, where there is no account to sign in with', function (): void {
    expect(User::query()->count())->toBe(0);

    Livewire::test(MobileRestoreFromBackup::class)
        ->assertOk()
        ->assertNoRedirect();
});

it('turns a set-up device away rather than offering to replace its data', function (): void {
    restoreScreenUser('restore-screen-occupied');

    Livewire::test(MobileRestoreFromBackup::class)
        ->assertRedirect(route('dashboard'));
});

// The one that matters. A Livewire action does not re-run mount(), so a client
// that posts straight to restore() skips the mount guard entirely. Without the
// second check this is an unauthenticated database replacement on a device that
// already belongs to somebody.
it('refuses the action itself on a set-up device, not only the mount', function (): void {
    $component = Livewire::test(MobileRestoreFromBackup::class);

    restoreScreenUser('restore-action-occupied');

    $component
        ->set('passphrase', 'whatever-passphrase')
        ->call('restore')
        ->assertRedirect(route('dashboard'));

    expect(User::query()->where('username', 'restore-action-occupied')->exists())->toBeTrue();
});

it('asks for the file before the passphrase, and says so', function (): void {
    Livewire::test(MobileRestoreFromBackup::class)
        ->call('restore')
        ->assertSet('error', Lang::get('core::backup.errors.choose_file'));
});

it('asks for the passphrase once a file is attached', function (): void {
    $enc = tempnam(sys_get_temp_dir(), 'beatrax-restore-').'.enc';
    file_put_contents($enc, 'not-a-real-backup');

    Livewire::test(MobileRestoreFromBackup::class)
        ->set('backup', UploadedFile::fake()->createWithContent('backup.enc', 'x'))
        ->call('restore')
        ->assertSet('error', Lang::get('core::backup.errors.enter_passphrase'));

    @unlink($enc);
});

it('says the upload failed rather than telling the reader to choose the file they chose', function (): void {
    Livewire::test(MobileRestoreFromBackup::class)
        ->call('uploadFailed')
        ->assertSet('error', Lang::get('core::backup.errors.upload_failed'));
});

it('does not demand a confirmation phrase, because there is nothing here to overwrite', function (): void {
    $source = (string) file_get_contents(
        base_path('Modules/Mobile/Internal/Http/Livewire/MobileRestoreFromBackup.php'),
    );

    expect($source)->not->toContain('CONFIRM_PHRASE');
    expect($source)->not->toContain('confirmation');
});
