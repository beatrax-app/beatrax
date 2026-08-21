<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Services\LocaleNegotiator;

beforeEach(function (): void {
    $this->negotiator = new LocaleNegotiator;
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
