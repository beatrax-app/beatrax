<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Services\LocaleNegotiator;

/*
 * Unit tests for the locale precedence rule (G7-R3..R8): an explicit
 * per-user choice beats a session choice beats the browser's best match,
 * and anything unsupported or absent falls through to English.
 */

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
    // A stale 'ja' override is skipped, not honoured; the session 'nl' wins.
    expect($this->negotiator->resolve('ja', 'nl', 'en'))->toBe('nl');
    // And when only unsupported values exist, English is the floor.
    expect($this->negotiator->resolve('ja', 'ko', 'zh'))->toBe('en');
});

it('honours every locale the enum declares', function (): void {
    foreach (Locale::cases() as $case) {
        expect($this->negotiator->resolve($case->value, null, null))->toBe($case->value);
    }
});
