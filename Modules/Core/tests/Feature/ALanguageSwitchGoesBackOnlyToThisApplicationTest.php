<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

// POST /locale is the one guest-reachable POST outside auth -- a device with no
// account must be able to switch language on the welcome screen -- and it put
// the Referer into Location as typed. CSRF and a no-referrer policy are what
// held it up, which is two other decisions doing this one's work.

function switchLocaleFrom(?string $referer): TestResponse
{
    return test()->withHeaders($referer === null ? [] : ['referer' => $referer])
        ->post('/locale', ['code' => 'nl']);
}

it('goes back to the page the reader was on', function (): void {
    switchLocaleFrom(config('app.url').'/help/data-locations')
        ->assertRedirect(config('app.url').'/help/data-locations');
});

// The positive control for the case above is that one: a same-origin Referer is
// honoured, so the refusals below are a judgement and not a route that always
// lands on the root.
it('refuses to carry the reader off this application', function (string $referer): void {
    switchLocaleFrom($referer)->assertRedirect('/');
})->with([
    'another host' => 'https://evil.test/phish',
    'a host this one is a prefix of' => 'http://localhost.evil.test/phish',
    'a backslash after the host' => 'http://localhost\\@evil.test/phish',
    'a scheme that is not a page' => 'javascript:alert(1)',
    'a protocol-relative address' => '//evil.test/phish',
    'a backslash protocol-relative address' => '/\\evil.test/phish',
]);

// What Laravel's own `from()` writes, and what a server-side caller writes: a
// root-relative path, which is this origin by construction.
it('goes back to a root-relative path', function (): void {
    switchLocaleFrom('/login')->assertRedirect('/login');
});

it('goes to the root when nothing said where the reader came from', function (): void {
    switchLocaleFrom(null)->assertRedirect('/');
});

it('remembers the choice whatever it does with the Referer', function (): void {
    switchLocaleFrom('https://evil.test/phish');

    expect(session()->all())->not->toBeEmpty();
});
