<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

function unreadableSecretsReader(): User
{
    return User::query()->create([
        'username' => 'unreadable-secrets-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

// The store is keyed by reader, so the file a half-written write leaves behind
// is this reader's own — not one global file the whole installation shares.
function unreadableSecretsGarbage(User $user): string
{
    $path = OpenBankingSecretsFixture::path((int) $user->id);
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, 'not json at all');

    return $path;
}

beforeEach(function (): void {
    $this->reader = unreadableSecretsReader();
});

afterEach(function (): void {
    OpenBankingSecretsFixture::forget((int) $this->reader->id);
});

// The credentials live in a file this screen neither writes nor owns, and a
// half-written or hand-edited one is an ordinary way for it to become
// unreadable. The settings page raised through it and answered 500, so the one
// screen that could have said which file to repair was the screen that crashed.
it('says the credentials cannot be read rather than answering a server fault', function (): void {
    unreadableSecretsGarbage($this->reader);

    $this->actingAs($this->reader)
        ->get('/settings/open-banking')
        ->assertOk()
        ->assertSee(Lang::get('openbanking::messages.page.credentials_unreadable'))
        ->assertSee(Lang::get('openbanking::messages.page.credentials_unreadable_next'));
});

it('reports it on the page state rather than only in the rendered copy', function (): void {
    unreadableSecretsGarbage($this->reader);

    $this->actingAs($this->reader);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('credentialsUnreadable', true)
        ->assertSet('connectionIds', [])
        ->assertSet('enabled', false);
});

it('says nothing about the credentials when there is no file to read', function (): void {
    $this->actingAs($this->reader)
        ->get('/settings/open-banking')
        ->assertOk()
        ->assertDontSee(Lang::get('openbanking::messages.page.credentials_unreadable'));
});

// The other half of the same file being unreadable: pressing Connect flashed
// the parser's own sentence, in English, naming the secrets file by absolute
// path -- onto the settings screen, in an app that ships twenty-six languages.
it('flashes a translated line rather than the parser sentence and the file path', function (): void {
    $path = unreadableSecretsGarbage($this->reader);

    $this->actingAs($this->reader)
        ->get('/oauth/connect/open-banking?institution_id=ASN_NL')
        ->assertRedirect(route('settings.open-banking'))
        ->assertSessionHas(
            'open_banking_failed',
            Lang::get('openbanking::messages.page.credentials_unreadable')
                .' '.Lang::get('openbanking::messages.page.credentials_unreadable_next'),
        );

    // The settings banner renders the situation and the remedy as two lines of
    // its own; a flash has one line to say both in. A reader who pressed
    // Connect was told what broke and nothing about what to do.
    expect(session('open_banking_failed'))->not->toContain(dirname($path))
        ->and(session('open_banking_failed'))->toContain(Lang::get('openbanking::messages.page.credentials_unreadable_next'));
});

// One reader's damaged file is not another's problem: the path names an owner,
// so a second reader's screen has nothing to report and nothing to refuse.
it('leaves a second reader unaffected by the first reader\'s damaged file', function (): void {
    unreadableSecretsGarbage($this->reader);
    $other = unreadableSecretsReader();

    try {
        $this->actingAs($other)
            ->get('/settings/open-banking')
            ->assertOk()
            ->assertDontSee(Lang::get('openbanking::messages.page.credentials_unreadable'));
    } finally {
        OpenBankingSecretsFixture::forget((int) $other->id);
    }
});
