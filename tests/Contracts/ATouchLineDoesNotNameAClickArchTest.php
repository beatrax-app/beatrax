<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/00-index.md
 */

// A `_touch` key exists for exactly one reason: the line beside it names a
// gesture the phone does not have. Deriving one is a substitution, and a
// substitution that matches nothing leaves the click in place while the key
// name promises it is gone — which is worse than not branching at all, because
// the reader now sees the same wrong word and the code claims otherwise.

/** @return array<string, string> locale => a pattern matching that locale's word for a click */
function clickStems(): array
{
    return [
        'bg' => 'клик|щрак', 'cs' => 'klik', 'da' => 'klik', 'de' => 'klick',
        'el' => 'κλικ', 'en' => 'click', 'es' => 'clic', 'et' => 'klõps',
        'fi' => 'napsaut', 'fr' => 'cliqu|clic', 'hr' => 'klik', 'hu' => 'kattint',
        'it' => 'clic', 'lt' => 'spustel', 'lv' => 'klikš', 'nb' => 'klikk',
        'nl' => 'klik', 'pl' => 'klik', 'pt' => 'clic', 'ro' => 'clic',
        'sk' => 'klik', 'sl' => 'klik', 'sr' => 'klik', 'sv' => 'klick',
        'tr' => 'tıkla', 'uk' => 'клац|клік',
    ];
}

it('never leaves a click inside a line named for touch', function (): void {
    $stems = clickStems();
    $offenders = [];
    $checked = 0;

    foreach (glob(base_path('Modules/*/Resources/lang/*/*.php')) ?: [] as $file) {
        if (preg_match('#/Resources/lang/([^/]+)/#', $file, $match) !== 1) {
            continue;
        }

        $locale = $match[1];
        $stem = $stems[$locale] ?? null;

        if ($stem === null) {
            continue;
        }

        /** @var array<string, mixed> $loaded */
        $loaded = require $file;

        foreach (Arr::dot($loaded) as $key => $value) {
            if (! is_string($value) || ! str_ends_with((string) $key, '_touch')) {
                continue;
            }

            $checked++;

            if (PatternScan::matches('/'.$stem.'/iu', $value)) {
                $offenders[] = str_replace(base_path().'/', '', $file)." [{$key}] ".$value;
            }
        }
    }

    // A walk that checked nothing would pass while proving nothing.
    expect($checked)->toBeGreaterThan(100);

    sort($offenders);

    expect($offenders)->toBe([], implode("\n  ", [
        'A line named for touch still tells the reader to click:',
        ...$offenders,
    ]));
});

it('gives every touch key a plain twin to fall back to on the desktop', function (): void {
    $orphans = [];

    foreach (glob(base_path('Modules/*/Resources/lang/*/*.php')) ?: [] as $file) {
        /** @var array<string, mixed> $loaded */
        $loaded = require $file;
        $flat = Arr::dot($loaded);

        foreach (array_keys($flat) as $key) {
            $key = (string) $key;

            if (! str_ends_with($key, '_touch')) {
                continue;
            }

            $plain = substr($key, 0, -strlen('_touch'));

            if (! array_key_exists($plain, $flat)) {
                $orphans[] = str_replace(base_path().'/', '', $file).' ['.$key.'] has no '.$plain;
            }
        }
    }

    expect($orphans)->toBe([], implode("\n  ", [
        'A touch line with no plain twin leaves the desktop reading a phone instruction:',
        ...$orphans,
    ]));
});
