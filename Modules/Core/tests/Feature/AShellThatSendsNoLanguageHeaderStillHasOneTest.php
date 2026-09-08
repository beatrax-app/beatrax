<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SystemLanguageSource;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Services\LocaleNegotiator;
use Modules\Core\tests\Support\FixedSystemLanguage;

uses(RefreshDatabase::class);

// An install with no account at all answers /login with the setup wizard, so
// the guest login screen these cases read only exists once somebody holds it.
beforeEach(function (): void {
    User::query()->create([
        'username' => 'language-probe',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

// The Android WebView forwards Cookie, Accept and User-Agent into PHP and no
// Accept-Language at all — measured on a Galaxy A51 running Dutch, which
// rendered every screen in English. iOS answers the same question through the
// same bridge, so the OS language is read there rather than from a header.

// Symfony's Request::create() defaults HTTP_ACCEPT_LANGUAGE to
// 'en-us,en;q=0.5', so the test client sends a header the Android WebView never
// does. Stripping it is what makes this case the phone's case rather than a
// desktop browser's.
it('takes the language from the OS when the request carries no header', function (): void {
    app()->instance(SystemLanguageSource::class, new FixedSystemLanguage('nl-NL'));

    $this->call('GET', '/login', server: ['HTTP_ACCEPT_LANGUAGE' => null])->assertOk();

    expect(app()->getLocale())->toBe('nl');
});

// The arm has to be REACHABLE, not merely present: getPreferredLanguage()
// answers the first entry of the supported set rather than null when nothing
// was sent, so passing its answer on unconditionally would rank a header
// nobody sent above the device that has one.
it('does not let an unsent header outrank the device', function (): void {
    expect(request()->getPreferredLanguage(Locale::codes()))->toBe('en');

    app()->instance(SystemLanguageSource::class, new FixedSystemLanguage('nl-NL'));

    $this->withHeaders(['Accept-Language' => ''])->get('/login')->assertOk();

    expect(app()->getLocale())->toBe('nl');
});

// A header that names a language is a language the reader was actually told
// to the server; the OS setting is only ever an inference about who is holding
// the phone, so it never overrides one.
it('keeps a language the request did name', function (): void {
    app()->instance(SystemLanguageSource::class, new FixedSystemLanguage('nl-NL'));

    $this->withHeaders(['Accept-Language' => 'de-DE,de;q=0.9'])->get('/login')->assertOk();

    expect(app()->getLocale())->toBe('de');
});

it('falls back to English when the OS names a language nothing is translated into', function (): void {
    app()->instance(SystemLanguageSource::class, new FixedSystemLanguage('ja-JP'));

    $this->get('/login')->assertOk();

    expect(app()->getLocale())->toBe('en');
});

// Region is dropped rather than matched: no shipped locale differs by region,
// and a Dutch reader in Belgium reads the same Dutch as one in the Netherlands.
it('reduces a platform tag to the code the registry is keyed by', function (): void {
    expect(Locale::fromTag('nl-NL'))->toBe('nl')
        ->and(Locale::fromTag('nl_BE'))->toBe('nl')
        ->and(Locale::fromTag('PT-br'))->toBe('pt')
        ->and(Locale::fromTag('en'))->toBe('en')
        ->and(Locale::fromTag('ja-JP'))->toBeNull()
        ->and(Locale::fromTag(''))->toBeNull();
});

it('ranks the device below the header and above English', function (): void {
    $negotiator = app(LocaleNegotiator::class);

    expect($negotiator->resolve(null, null, 'de', 'nl'))->toBe('de')
        ->and($negotiator->resolve(null, null, null, 'nl'))->toBe('nl')
        ->and($negotiator->resolve(null, 'fr', null, 'nl'))->toBe('fr')
        ->and($negotiator->resolve('es', null, 'de', 'nl'))->toBe('es')
        ->and($negotiator->resolve(null, null, null, null))->toBe('en');
});
