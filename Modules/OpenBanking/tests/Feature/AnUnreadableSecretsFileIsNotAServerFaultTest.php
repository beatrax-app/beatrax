<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;

function unreadableSecretsReader(): User
{
    return User::query()->create([
        'username' => 'unreadable-secrets-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function unreadableSecretsPath(): string
{
    return UserDataPathService::appPath('secrets/open-banking.json');
}

afterEach(function (): void {
    $path = unreadableSecretsPath();
    if (is_file($path)) {
        unlink($path);
    }
});

// The credentials live in one file this screen neither writes nor owns, and a
// half-written or hand-edited one is an ordinary way for it to become
// unreadable. The settings page raised through it and answered 500, so the one
// screen that could have said which file to repair was the screen that crashed.
it('says the credentials cannot be read rather than answering a server fault', function (): void {
    $user = unreadableSecretsReader();
    $path = unreadableSecretsPath();
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, 'not json at all');

    $this->actingAs($user)
        ->get('/settings/open-banking')
        ->assertOk()
        ->assertSee(Lang::get('openbanking::messages.page.credentials_unreadable'))
        ->assertSee(Lang::get('openbanking::messages.page.credentials_unreadable_next'));
});

it('says nothing about the credentials when there is no file to read', function (): void {
    $user = unreadableSecretsReader();

    $this->actingAs($user)
        ->get('/settings/open-banking')
        ->assertOk()
        ->assertDontSee(Lang::get('openbanking::messages.page.credentials_unreadable'));
});

// The other half of the same file being unreadable: pressing Connect flashed
// the parser's own sentence, in English, naming the secrets file by absolute
// path -- onto the settings screen, in an app that ships twenty-six languages.
it('flashes a translated line rather than the parser sentence and the file path', function (): void {
    $user = unreadableSecretsReader();
    $path = unreadableSecretsPath();
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, 'not json at all');

    $this->actingAs($user)
        ->get('/oauth/connect/open-banking?institution_id=ASN_NL')
        ->assertRedirect(route('settings.open-banking'))
        ->assertSessionHas(
            'open_banking_failed',
            Lang::get('openbanking::messages.page.credentials_unreadable'),
        );

    expect(session('open_banking_failed'))->not->toContain(dirname($path));
});
