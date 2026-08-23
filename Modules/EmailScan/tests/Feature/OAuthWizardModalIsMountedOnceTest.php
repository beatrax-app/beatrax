<?php

declare(strict_types=1);

use Modules\Core\Models\User;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'oauth-wizard-mount',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('renders the OAuth client wizard exactly once on the inboxes page', function (): void {
    // open() listens on a global Livewire event, so every mounted instance
    // opens. On a phone that put two identical dialogs on top of each other:
    // closing one left an unchanged copy behind it.
    $html = (string) $this->get('/inboxes')->assertOk()->getContent();

    expect(substr_count($html, 'wire:name="email-scan.oauth-client-wizard-modal"'))->toBe(1);
});

it('lets the redirect URI break inside the dialog it sizes', function (): void {
    // The URI is one unbreakable token and the button shrinks to fit, so its
    // min-content width became the <dialog>'s width: 441px on a 411px phone,
    // with the body text clipped and the close button half off-screen.
    $template = (string) file_get_contents(
        base_path('Modules/EmailScan/Resources/views/livewire/oauth-client-wizard-modal.blade.php'),
    );

    preg_match_all('/<span[^>]*>\{\{ \$redirectUri \}\}<\/span>/', $template, $spans);

    expect($spans[0])->not->toBeEmpty();

    foreach ($spans[0] as $span) {
        expect($span)->toContain('break-all');
    }
});
