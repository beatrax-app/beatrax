<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Symfony\Component\Finder\Finder;

// The rules page drew a chip reading ALL beside a Dutch sentence for as long as
// the screen has existed, because the two words were typed into a ternary and
// no translation guard reads a template for words it was never given a key for.
// The concept was translated one screen away the whole time.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#english-a-template-typed-for-itself

// Every literal on this tree that is capitalised inside a blade echo and is not
// copy. Each is a name the app does not own — two mail providers and the
// product itself — or the word a database hands back for an absent value in the
// SELECT-only browser. This is where a genuine exception belongs; it is short
// because the shape is rare, and it stays short by being argued each time.
const BLADE_LITERAL_NOT_COPY = [
    'Beatrax',
    'Gmail',
    'Microsoft 365',
    'NULL',
];

// The attributes a reader is shown the value of. `alt`, `title` and
// `aria-label` are read aloud or on hover; a `placeholder` is on screen until
// the box is typed into. Everything else an element carries is machinery.
const BLADE_VISIBLE_ATTRIBUTES = [
    'title', 'alt', 'placeholder',
    'aria-label', 'aria-description', 'aria-roledescription', 'aria-valuetext',
];

// Each entry names a file whose visible attribute holds English that is not
// copy, and why. The `proves` pattern re-checks the reason: when it stops
// matching, the exemption has outlived what earned it.
const BLADE_ATTRIBUTE_PINS = [
    'Modules/DevMode/Resources/views/livewire/sql-panel-page.blade.php' => [
        'reason' => 'a sample query in the SELECT-only browser: SQL keywords are the language of the box, and a translated SELECT would not run',
        'proves' => '/SELECT \* FROM/',
    ],
];

function bladeSpeakingFiles(): Finder
{
    return Finder::create()
        ->files()
        ->in([base_path('Modules'), base_path('resources/views')])
        ->name('*.blade.php')
        ->notPath('tests');
}

// A Blade comment is prose by definition and is stripped before either rule
// looks at the file, or every sentence a template explains itself with would be
// read as copy the template speaks.
function bladeSpeakingSource(string $source): string
{
    return PatternScan::replace('/\{\{--.*?--\}\}/s', '', $source);
}

/** @return list<string> the quoted literals inside this template's echoes */
function bladeSpeakingEchoLiterals(string $source, int &$echoes): array
{
    $matches = PatternScan::sets('/\{\{(?!--)(.*?)\}\}|\{!!(.*?)!!\}/s', $source);

    $literals = [];

    foreach ($matches as $match) {
        $expression = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
        $echoes++;

        // A date pattern is letters and spaces and nothing else — 'd M Y' and
        // 'F Y' are indistinguishable from copy by their characters alone. They
        // are told apart by what they are an argument TO, which is exact.
        $expression = PatternScan::replace(
            '/(?:->|::|\b)(?:translatedFormat|isoFormat|format|date)\s*\(([^()]*)\)/i',
            '',
            $expression,
        );

        $quoted = PatternScan::sets('/\'([^\'\\\\]*)\'|"([^"\\\\]*)"/', $expression);

        foreach ($quoted as $one) {
            $literal = ($one[1] ?? '') !== '' ? $one[1] : ($one[2] ?? '');
            if ($literal !== '') {
                $literals[] = $literal;
            }
        }
    }

    return $literals;
}

// Copy is capitalised and a machine token is not: an array key, a CSS class, a
// wire method, an aria value and an inputmode all read lower case, and none of
// them reaches a reader as a word. A camelCase identifier is excluded by the
// hump that makes it one, which is why `$rule->active` and `createPot` are not
// mistaken for a sentence.
function bladeSpeakingReadsAsCopy(string $literal): bool
{
    if (in_array($literal, BLADE_LITERAL_NOT_COPY, true)) {
        return false;
    }

    if (! PatternScan::matches('/^[A-Za-z][A-Za-z0-9 \'’.,!?()-]+$/u', $literal)) {
        return false;
    }

    return PatternScan::matches('/[A-Z]/', $literal)
        && ! PatternScan::matches('/[a-z][A-Z]/', $literal);
}

it('never echoes a word it typed for itself', function (): void {
    $echoes = 0;
    $offenders = [];

    foreach (bladeSpeakingFiles() as $file) {
        $relative = str_replace(base_path().'/', '', $file->getPathname());
        $source = bladeSpeakingSource((string) $file->getContents());

        foreach (bladeSpeakingEchoLiterals($source, $echoes) as $literal) {
            if (bladeSpeakingReadsAsCopy($literal)) {
                $offenders[] = $relative.' — "'.$literal.'"';
            }
        }
    }

    $offenders = array_values(array_unique($offenders));

    // Thousands of echoes stand in these templates. A run that reads none of
    // them found nothing because it stopped, not because it is clean.
    expect($echoes)->toBeGreaterThan(1000, 'No blade echo was read, so this rule checked nothing.');

    expect($offenders)->toBe([], implode("\n", [
        'These print a word the template typed rather than one a reader was given:',
        ...$offenders,
        '',
        'The app ships twenty-six languages and a literal has none of them. It is the',
        'one shape no translation guard can see: EveryTranslatedLineReachesAReader and',
        'TranslationParityArchTest both start from a lang file, and a word that never',
        'had a key is in neither. It took a screenshot of a Dutch /rules to find "ALL"',
        'standing between "Prioriteit 1" and \'Omschrijving bevat "Albert"\'.',
        '',
        'Give it a key and read it with Lang::get(). Where the same concept is already',
        'translated elsewhere, reuse that locale\'s word rather than a second one —',
        'the chip and the form field that set it have to say the same thing.',
        '',
        'A ternary picking between two of them picks between two KEYS instead:',
        '  {{ Lang::get($isAny ? \'ns::group.any\' : \'ns::group.all\') }}',
        '',
        'The rule reads capitalisation, because that is what separates copy from the',
        'machine words a template is full of — an array key, a CSS class, a wire',
        'method, an aria value. A name the app does not own goes in',
        'BLADE_LITERAL_NOT_COPY at the top of this file, with the argument for it.',
    ]));
});

it('never puts a sentence a reader hears into an attribute of its own', function (): void {
    $attributes = 0;
    $offenders = [];
    $pinned = [];

    $pattern = '/(?<![\w:.-])(?:'.implode('|', array_map(
        static fn (string $attribute): string => preg_quote($attribute, '/'),
        BLADE_VISIBLE_ATTRIBUTES,
    )).')\s*=\s*"([^"]*)"/i';

    foreach (bladeSpeakingFiles() as $file) {
        $relative = str_replace(base_path().'/', '', $file->getPathname());
        $source = bladeSpeakingSource((string) $file->getContents());
        $matches = PatternScan::sets($pattern, $source);

        foreach ($matches as $match) {
            $attributes++;
            $value = trim($match[1]);

            // A value carrying an echo, a variable or a translator call is
            // already reaching the reader through a key; only a bare one is
            // the template speaking.
            foreach (['{{', '{!!', '$', 'Lang::', '@lang'] as $reachesAKey) {
                if (str_contains($value, $reachesAKey)) {
                    continue 2;
                }
            }

            // Two words of letters is a phrase somebody wrote. One is a brand,
            // a format example or a code, and every one of those on this tree
            // is exactly that.
            if (! PatternScan::matches('/[A-Za-z]{2,}\s+[A-Za-z]{2,}/', $value)) {
                continue;
            }

            if (array_key_exists($relative, BLADE_ATTRIBUTE_PINS)) {
                $pinned[$relative] = true;

                continue;
            }

            $offenders[] = $relative.' — '.trim($match[0]);
        }
    }

    expect($attributes)->toBeGreaterThan(50, 'No visible attribute was read, so this rule checked nothing.');

    expect($offenders)->toBe([], implode("\n", [
        'These hand a reader a phrase through an attribute, in one language:',
        ...$offenders,
        '',
        'A title, an alt and an aria-label are read aloud or on hover, and a placeholder',
        'sits on screen until the box is typed into. All four are copy, and none of them',
        'is caught by the echo rule above, because nothing is being echoed.',
        '',
        'Route it through Lang::get() like the visible text beside it.',
    ]));

    // A pin nobody reaches any more is a claim about the tree that stopped
    // being true, and it would otherwise sit here forever.
    expect(array_keys($pinned))->toBe(array_keys(BLADE_ATTRIBUTE_PINS));
});

it('still holds each pinned exemption to the reason it was granted for', function (): void {
    foreach (BLADE_ATTRIBUTE_PINS as $relative => $pin) {
        $source = (string) file_get_contents(base_path($relative));

        expect($source)->toMatch($pin['proves'], $relative.' no longer reads as "'.$pin['reason'].'"');
    }
});
