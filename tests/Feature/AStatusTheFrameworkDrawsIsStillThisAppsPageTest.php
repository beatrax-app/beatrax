<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;

uses(RefreshDatabase::class);

// Laravel resolves `errors::{status}` before `errors::4xx`, and ships views of
// its own for 401, 402, 403 and 429. So the generic page never fired for them:
// a 403 answered the framework's "Forbidden", in English, while the locale
// bound on the application was Dutch.

function frameworkStatusReader(string $locale): User
{
    return User::query()->create([
        'username' => 'framework-status',
        'password' => 'test-password',
        'is_developer' => false,
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'locale' => $locale,
    ]);
}

it('answers a forbidden request with this app’s own page, in the reader’s language', function (): void {
    Route::get('/refused-for-the-test', fn () => abort(403))->middleware(['web']);

    $response = test()->actingAs(frameworkStatusReader('nl'))->get('/refused-for-the-test');

    expect($response->getStatusCode())->toBe(403);

    $body = (string) $response->getContent();
    $translator = app(Translator::class);
    $dutch = (string) $translator->get('core::errors.4xx.title', [], 'nl');
    $english = (string) $translator->get('core::errors.4xx.title', [], 'en');

    // The two must differ, or this asserts nothing at all.
    expect($dutch)->not->toBe($english);

    expect($body)
        ->toContain('bx-error')              // the app's shell, not the framework's
        ->toContain($dutch)
        ->not->toContain('<h1>403</h1>');    // the framework page's own heading

    expect(PatternScan::matches('/<html[^>]*lang="nl"/', $body))->toBeTrue();
});

it('offers the way back that the framework page has none of', function (): void {
    Route::get('/refused-for-the-test-two', fn () => abort(403))->middleware(['web']);

    $body = (string) test()->actingAs(frameworkStatusReader('nl'))->get('/refused-for-the-test-two')->getContent();

    // The app is a shell with no address bar and no browser back, so a status
    // page without a route onward is a dead end.
    expect($body)->toContain((string) app(Translator::class)->get('core::errors.back', [], 'nl'));
});
