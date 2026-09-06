<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\PatternScan;

// Whether a percent sign is closed up ("42%"), spaced ("42 %") or written in
// front ("%42") is the locale's own convention, not a house style — and the
// tree held both spellings at once, so a Dutch reader met "±5%" on Settings
// and "0 %" on Triage. CLDR is the authority, so ask it rather than argue.
/**
 * @return array<string, string> locale => CLOSED|SPACE|PREFIX
 */
function percentSignConventions(): array
{
    $conventions = [];
    foreach (Locale::cases() as $locale) {
        $rendered = (new NumberFormatter($locale->value, NumberFormatter::PERCENT))->format(0.42);
        $conventions[$locale->value] = match (true) {
            str_starts_with((string) $rendered, '%') => 'PREFIX',
            preg_match('/(\s|\x{00a0}|\x{202f})%/u', (string) $rendered) === 1 => 'SPACE',
            default => 'CLOSED',
        };
    }

    return $conventions;
}

/**
 * @param  array<array-key, mixed>  $strings
 * @return array<string, string>
 */
function percentSignFlatten(array $strings, string $prefix = ''): array
{
    $flat = [];
    foreach ($strings as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $flat += percentSignFlatten($value, $path);
        } elseif (is_string($value)) {
            $flat[$path] = $value;
        }
    }

    return $flat;
}

// A percent sign is only one when a figure is against it: a bare `%` is a
// printf specifier or prose, and `%s`/`%d` are excluded on the same grounds.
const PERCENT_SIGN_TOKEN = '/(?:(\d|:[a-z_]+)(\s|\x{00a0}|\x{202f})?%(?![sdu]))|(?:%(?::[a-z_]+|\d))/u';

// The framework's own root catalogue is read beside the modules': it holds a
// hundred files in the same 26 locales, and a rule about how a reader is shown
// a figure has no reason to stop at a directory boundary.
/** @return list<string> every translation file the product ships */
function percentSignCatalogues(): array
{
    $files = [];

    foreach (['Modules/*/Resources/lang/*/*.php', 'lang/*/*.php'] as $pattern) {
        foreach (glob(base_path($pattern)) ?: [] as $path) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

/**
 * How $value spells each percent sign it holds, one entry per sign.
 *
 * @return list<string> each CLOSED, SPACE or PREFIX
 */
function percentSignSpellings(string $value): array
{
    $spellings = [];

    foreach (PatternScan::sets(PERCENT_SIGN_TOKEN, $value) as $token) {
        $spellings[] = match (true) {
            ($token[1] ?? '') === '' => 'PREFIX',
            ($token[2] ?? '') !== '' => 'SPACE',
            default => 'CLOSED',
        };
    }

    return $spellings;
}

// The modules' catalogues hold 418 percent signs and the root's hold none, and
// a walk that stops reading finds nothing and calls the tree clean.
const PERCENT_SIGN_CELL_FLOOR = 300;

it('spells a percent sign the way each locale spells it', function (): void {
    $conventions = percentSignConventions();

    // English-only ICU data reports every locale the same way, which would let
    // this walk pass a tree it never really read. The shipped set spans all
    // three conventions, so anything less means the data is not there.
    expect(array_unique(array_values($conventions)))
        ->toHaveCount(3, 'ICU returned one convention for every locale — its locale data is missing.');

    $offenders = [];
    $cells = 0;

    foreach (percentSignCatalogues() as $path) {
        if (preg_match('#/lang/([a-z]{2})/#', $path, $matches) !== 1) {
            continue;
        }

        // A directory naming a locale the product does not ship has no
        // convention to be held to, and CLDR would answer for a reader who is
        // never shown it.
        $locale = $matches[1];
        if (! array_key_exists($locale, $conventions)) {
            continue;
        }

        /** @var array<array-key, mixed> $strings */
        $strings = require $path;

        foreach (percentSignFlatten($strings) as $key => $value) {
            foreach (percentSignSpellings($value) as $spelling) {
                $cells++;

                if ($spelling !== $conventions[$locale]) {
                    $offenders[] = str_replace(base_path().'/', '', $path).' ['.$key.'] '
                        .$locale.' writes '.$spelling.', CLDR says '.$conventions[$locale].': '.$value;
                }
            }
        }
    }

    expect($cells)->toBeGreaterThan(
        PERCENT_SIGN_CELL_FLOOR,
        'The reader found '.$cells.' percent signs across '.count(percentSignCatalogues())
        .' catalogues, which is what a walk that stopped reading looks like: no sign found is no sign to judge.'
    );

    expect($offenders)->toBe([], implode("\n", [
        'These strings spell the percent sign against their own locale:',
        ...$offenders,
        '',
        'CLDR decides this per locale, and the three answers it gives are',
        '"42%", "42 %" and "%42". Match the one the locale asks for rather',
        'than the one the English line happens to use.',
    ]));
});

// A guard that cannot go red says nothing, and the verdict above is read off one
// reader. It is checked against the three spellings CLDR gives rather than
// against the tree, so a rewrite of the token cannot quietly stop finding them.
it('reads each spelling of a percent sign, and nothing that is not one', function (string $value, array $spellings): void {
    expect(percentSignSpellings($value))->toBe($spellings);
})->with([
    'closed up' => ['42% of the budget', ['CLOSED']],
    'spaced' => ['42 % van het budget', ['SPACE']],
    'a non-breaking space' => ["42\u{00a0}% du budget", ['SPACE']],
    'written in front' => ['%42 av budsjettet', ['PREFIX']],
    'a placeholder against the sign' => [':share% of the budget', ['CLOSED']],
    'a printf string specifier' => ['%s of the budget', []],
    'a bare sign in prose' => ['the % key', []],
    'two signs in one line' => ['42% and 7 %', ['CLOSED', 'SPACE']],
]);
