<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\Theme;

/*
 * Unit tests for the appearance-preference SSoT: coercion of a stored or
 * guest value, the server-side dark resolution given an OS signal, when the
 * pre-paint prefers-color-scheme script must run, and the validation
 * allow-list.
 */

it('coerces null and unrecognised values onto the system default', function (): void {
    expect(Theme::coerce(null))->toBe(Theme::System);
    expect(Theme::coerce('purple'))->toBe(Theme::System);
    expect(Theme::coerce('light'))->toBe(Theme::Light);
    expect(Theme::coerce('dark'))->toBe(Theme::Dark);
    expect(Theme::coerce('system'))->toBe(Theme::System);
});

it('reports dark only for an explicit dark, or a system theme on a dark OS', function (): void {
    expect(Theme::Dark->isDark(null))->toBeTrue();
    expect(Theme::Dark->isDark('light'))->toBeTrue();
    expect(Theme::Light->isDark('dark'))->toBeFalse();
    expect(Theme::System->isDark('dark'))->toBeTrue();
    expect(Theme::System->isDark('light'))->toBeFalse();
    expect(Theme::System->isDark(null))->toBeFalse();
});

it('needs the pre-paint script only for a system theme with no OS answer', function (): void {
    expect(Theme::System->needsPrePaintScript(null))->toBeTrue();
    expect(Theme::System->needsPrePaintScript('dark'))->toBeFalse();
    expect(Theme::Dark->needsPrePaintScript(null))->toBeFalse();
    expect(Theme::Light->needsPrePaintScript(null))->toBeFalse();
});

it('exposes the allow-list values in declared order', function (): void {
    expect(Theme::values())->toBe(['light', 'dark', 'system']);
});

it('defaults to system', function (): void {
    expect(Theme::DEFAULT)->toBe('system');
    expect(Theme::from(Theme::DEFAULT))->toBe(Theme::System);
});
