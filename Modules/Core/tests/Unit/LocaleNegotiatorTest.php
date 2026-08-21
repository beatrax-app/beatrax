<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Services\LocaleNegotiator;

beforeEach(function (): void {
    $this->negotiator = app(LocaleNegotiator::class);
});

it('prefers an explicit user override above everything else', function (): void {
    expect($this->negotiator->resolve('nl', 'en', 'en'))->toBe('nl');
});

it('falls to the session choice when the user has no override', function (): void {
    expect($this->negotiator->resolve(null, 'nl', 'en'))->toBe('nl');
});

it('falls to the browser preference when there is no user or session choice', function (): void {
    expect($this->negotiator->resolve(null, null, 'nl'))->toBe('nl');
});

it('defaults to English when every signal is absent', function (): void {
    expect($this->negotiator->resolve(null, null, null))->toBe(Locale::DEFAULT)
        ->and(Locale::DEFAULT)->toBe('en');
});

it('ignores an unsupported value and continues down the chain', function (): void {
    expect($this->negotiator->resolve('ja', 'nl', 'en'))->toBe('nl');
    expect($this->negotiator->resolve('ja', 'ko', 'zh'))->toBe('en');
});

it('honours every locale the enum declares', function (): void {
    foreach (Locale::cases() as $case) {
        expect($this->negotiator->resolve($case->value, null, null))->toBe($case->value);
    }
});

// apply() is the one call the whole stack follows: the translator behind
// Lang::get and Fmt::number, the config value Livewire replays on hydrate, and
// Carbon, which owns the language of every translatedFormat date and hears
// about a change only through the event Application::setLocale raises.
it('retargets the translator, the replayed config value and Carbon together', function (): void {
    CarbonImmutable::setLocale('en');

    $this->negotiator->apply('nl');

    expect(app('translator')->getLocale())->toBe('nl')
        ->and(app()->getLocale())->toBe('nl')
        ->and(CarbonImmutable::getLocale())->toBe('nl');
});

it('remembers a supported choice under the session key SetLocale reads', function (): void {
    /** @var Session $session */
    $session = app(Session::class);

    $this->negotiator->rememberChoice($session, 'nl');

    expect($session->get('locale'))->toBe('nl');
});

it('clears the key for System rather than storing it as a language', function (): void {
    /** @var Session $session */
    $session = app(Session::class);
    $session->put('locale', 'nl');

    $this->negotiator->rememberChoice($session, LocaleNegotiator::SYSTEM);

    expect($session->has('locale'))->toBeFalse();
});

it('drops a code outside the supported set and leaves the previous choice standing', function (): void {
    /** @var Session $session */
    $session = app(Session::class);
    $session->put('locale', 'nl');

    $this->negotiator->rememberChoice($session, 'ja');

    expect($session->get('locale'))->toBe('nl');
});
