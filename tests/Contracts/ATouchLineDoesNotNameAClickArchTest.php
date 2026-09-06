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

/** @return list<string> every locale a module ships a lang directory for */
function clickStemLocales(): array
{
    $locales = [];

    foreach (glob(base_path('Modules/*/Resources/lang/*'), GLOB_ONLYDIR) ?: [] as $directory) {
        $locales[basename($directory)] = true;
    }

    $found = array_keys($locales);
    sort($found);

    return $found;
}

// A map keyed on a list of locales cannot see a locale that is not in it, and
// a locale added without a stem is skipped in silence — which reads exactly
// like a locale with no offender in it.
it('carries a word for a click in every locale the tree ships', function (): void {
    $locales = clickStemLocales();

    expect(count($locales))->toBeGreaterThan(
        20,
        'Almost no locale directories were found, so the comparison below is about a tree nobody walked.',
    );

    expect(array_values(array_diff($locales, array_keys(clickStems()))))->toBe([], implode("\n  ", [
        'These locales ship translations and no stem for the word "click", so the rule below skips them',
        'without saying so. Add the locale\'s own word for a click to clickStems().',
    ]));

    expect(array_values(array_diff(array_keys(clickStems()), $locales)))->toBe([], implode("\n  ", [
        'These stems name a locale the tree no longer ships. The entry matches nothing and reads as',
        'coverage — delete it.',
    ]));
});

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
    expect($checked)->toBeGreaterThan(
        100,
        'No _touch line was read, so the offender list below is empty because the scan stopped, not because the tree is clean.',
    );

    sort($offenders);

    expect($offenders)->toBe([], implode("\n  ", [
        'A line named for touch still tells the reader to click:',
        ...$offenders,
    ]));
});

it('gives every touch key a plain twin to fall back to on the desktop', function (): void {
    $orphans = [];
    $touchKeys = 0;

    foreach (glob(base_path('Modules/*/Resources/lang/*/*.php')) ?: [] as $file) {
        /** @var array<string, mixed> $loaded */
        $loaded = require $file;
        $flat = Arr::dot($loaded);

        foreach (array_keys($flat) as $key) {
            $key = (string) $key;

            if (! str_ends_with($key, '_touch')) {
                continue;
            }

            $touchKeys++;
            $plain = substr($key, 0, -strlen('_touch'));

            if (! array_key_exists($plain, $flat)) {
                $orphans[] = str_replace(base_path().'/', '', $file).' ['.$key.'] has no '.$plain;
            }
        }
    }

    expect($touchKeys)->toBeGreaterThan(
        100,
        'No _touch key was read, so the orphan list below is empty because the scan stopped, not because every twin is there.',
    );

    expect($orphans)->toBe([], implode("\n  ", [
        'A touch line with no plain twin leaves the desktop reading a phone instruction:',
        ...$orphans,
    ]));
});

// The rule is one PatternScan::matches call per line, and a stem that stopped
// compiling would answer false for every locale at once — the same answer a
// clean tree gives.
it('reads a click written in the locale it is checking, and leaves a clean line alone', function (): void {
    $stems = clickStems();

    expect(PatternScan::matches('/'.$stems['nl'].'/iu', 'Klik op een categorie'))->toBeTrue('a Dutch click went unreported');
    expect(PatternScan::matches('/'.$stems['nl'].'/iu', 'Tik op een categorie'))->toBeFalse('a Dutch tap was read as a click');
    expect(PatternScan::matches('/'.$stems['uk'].'/iu', 'Клацніть на категорію'))->toBeTrue('a Cyrillic click went unreported');
    expect(PatternScan::matches('/'.$stems['en'].'/iu', 'Tap a category'))->toBeFalse('an English tap was read as a click');
});
