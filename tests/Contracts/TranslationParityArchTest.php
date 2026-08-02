<?php

declare(strict_types=1);

/*
 * en/nl translation parity (G7).
 *
 * Every module lang file ships in both shipped locales with the identical
 * set of (possibly nested) key paths, so a new en key cannot merge without
 * its Dutch translation and a removed key cannot leave a stale nl entry
 * behind. The check compares key paths, not values — a key whose Dutch
 * copy is intentionally identical to English (a proper noun, a symbol)
 * still satisfies parity.
 */

/**
 * @param  array<array-key, mixed>  $translations
 * @return list<string>
 */
function translationParityKeyPaths(array $translations, string $prefix = ''): array
{
    $paths = [];
    foreach ($translations as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $paths = array_merge($paths, translationParityKeyPaths($value, $path));
        } else {
            $paths[] = $path;
        }
    }

    return $paths;
}

it('ships every en translation key in nl too, per module lang file', function (): void {
    $enFiles = glob(base_path('Modules/*/Resources/lang/en/*.php')) ?: [];
    expect($enFiles)->not->toBeEmpty();

    $problems = [];
    foreach ($enFiles as $enFile) {
        $nlFile = str_replace('/lang/en/', '/lang/nl/', $enFile);
        $rel = str_replace(base_path().'/', '', $enFile);

        if (! is_file($nlFile)) {
            $problems[] = $rel.': no nl counterpart file';

            continue;
        }

        $en = require $enFile;
        $nl = require $nlFile;
        if (! is_array($en) || ! is_array($nl)) {
            $problems[] = $rel.': a lang file did not return an array';

            continue;
        }

        $enKeys = translationParityKeyPaths($en);
        $nlKeys = translationParityKeyPaths($nl);
        sort($enKeys);
        sort($nlKeys);

        $missing = array_values(array_diff($enKeys, $nlKeys));
        $stale = array_values(array_diff($nlKeys, $enKeys));
        if ($missing !== []) {
            $problems[] = $rel.': nl is missing ['.implode(', ', $missing).']';
        }
        if ($stale !== []) {
            $problems[] = $rel.': nl has stale ['.implode(', ', $stale).']';
        }
    }

    expect($problems)->toBe([], "en/nl translation parity broken:\n  ".implode("\n  ", $problems));
});

it('has no nl translation file without an en counterpart', function (): void {
    $nlFiles = glob(base_path('Modules/*/Resources/lang/nl/*.php')) ?: [];

    $orphans = [];
    foreach ($nlFiles as $nlFile) {
        $enFile = str_replace('/lang/nl/', '/lang/en/', $nlFile);
        if (! is_file($enFile)) {
            $orphans[] = str_replace(base_path().'/', '', $nlFile);
        }
    }

    expect($orphans)->toBe([]);
});
