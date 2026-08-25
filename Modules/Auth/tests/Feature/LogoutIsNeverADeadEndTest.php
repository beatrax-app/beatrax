<?php

declare(strict_types=1);

use Modules\Core\Models\User;

// The mobile shell re-requests the URL a sign-out was posted to, so `/logout`
// was reached with GET and Laravel answered 405. Under a dev build's APP_DEBUG
// that is a stack trace over this app's own source, on a page carrying no
// navigation at all -- the reader's only exit is an address bar they do not
// have on a phone. It is the last thing they do in a session.
function logoutDeadEndUser(): User
{
    return User::query()->create([
        'username' => 'logout-dead-end',
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('answers a GET to the sign-out URL with the sign-in screen, not a 405', function (): void {
    $response = $this->actingAs(logoutDeadEndUser())->get('/logout');

    expect($response->getStatusCode())->not->toBe(405);
    $response->assertRedirect(route('login'));
});

it('signs nobody out on a GET, because an <img> could make one', function (): void {
    $user = logoutDeadEndUser();

    $this->actingAs($user)->get('/logout');

    expect(auth()->check())->toBeTrue();
});

it('still signs out on the POST that means it', function (): void {
    $this->actingAs(logoutDeadEndUser())->post('/logout')->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

// Laravel falls back to 4xx.blade.php for any 4xx without a view of its own.
// Without it the framework's page reaches the reader, and this app has spent
// the round establishing that its own error page is the one that says
// something useful and offers a way back.
it('owns the page for a 4xx it has not styled by name', function (): void {
    foreach (['resources/views/errors/4xx.blade.php', 'mobile-app/resources/views/errors/4xx.blade.php'] as $path) {
        expect(is_file(base_path($path)))->toBeTrue($path.' is missing');
        expect((string) file_get_contents(base_path($path)))->toContain('errors.beatrax-error');
    }
});
