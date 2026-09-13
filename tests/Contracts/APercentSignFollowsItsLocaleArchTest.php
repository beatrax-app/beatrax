<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\PatternScan;
use Symfony\Component\Finder\Finder;

// Whether a percent sign is closed up ("42%"), spaced ("42 %") or written in
// front ("%42") is the locale's own convention, not a house style — and the
// tree held both spellings at once, so a Dutch reader met "±5%" on Settings
// and "0 %" on Triage. CLDR is the authority, so ask it rather than argue.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md

// Which space, not merely whether one: CLDR names the NO-BREAK space, and a
// plain one lets the sign wrap to a line of its own. The two are invisible
// side by side and the tree held both, so the spelling carries its bytes.
/**
 * @return array<string, string> locale => PREFIX, or GAP: and the space it keeps
 */
function percentSignConventions(): array
{
    $conventions = [];
    foreach (Locale::cases() as $locale) {
        $rendered = (string) (new NumberFormatter($locale->value, NumberFormatter::PERCENT))->format(0.42);
        $gap = PatternScan::first('/(\s|\x{00a0}|\x{202f})%/u', $rendered);

        $conventions[$locale->value] = str_starts_with($rendered, '%')
            ? 'PREFIX'
            : 'GAP:'.bin2hex($gap[1] ?? '');
    }

    return $conventions;
}

// Fmt::percent() cannot ask ICU: the mobile build's ext-intl carries
// English-only locale data, so on device it would answer for English
// twenty-five times. The enum transcribes CLDR for that reason, and this is
// what keeps the transcription honest.
/** @return array<string, string> the same vocabulary, read off the tree's own table */
function percentSignTranscribed(): array
{
    $transcribed = [];
    foreach (Locale::cases() as $locale) {
        $transcribed[$locale->value] = $locale->percentSignBeforeDigits()
            ? 'PREFIX'
            : 'GAP:'.bin2hex($locale->percentGap());
    }

    return $transcribed;
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
function percentSignCatalogs(): array
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
 * @return list<string> each PREFIX, or GAP: and the space it keeps
 */
function percentSignSpellings(string $value): array
{
    $spellings = [];

    foreach (PatternScan::sets(PERCENT_SIGN_TOKEN, $value) as $token) {
        $spellings[] = ($token[1] ?? '') === ''
            ? 'PREFIX'
            : 'GAP:'.bin2hex($token[2] ?? '');
    }

    return $spellings;
}

// The modules' catalogues hold 418 percent signs and the root's hold none, and
// a walk that stops reading finds nothing and calls the tree clean.
const PERCENT_SIGN_CELL_FLOOR = 300;

// A catalogue can spell the sign its locale's way because it IS one locale. A
// template is all twenty-six at once, so there is no spelling it can type that
// is right: thirteen readers want a no-break space, one wants it in front, and
// twelve want it closed up. Fmt::percent() is where that is known.

// Machinery, not copy: a CSS length, a Tailwind class and a chart geometry are
// percentages of a box rather than of anything a reader is told.
const PERCENT_SIGN_MACHINERY = [
    '/\{\{--.*?--\}\}/s',
    '/<style\b.*?<\/style>/s',
    '/\bstyle\s*=\s*"[^"]*"/s',
    "/\bstyle\s*=\s*'[^']*'/s",
    '/\bclass\s*=\s*"[^"]*"/s',
    "/\bclass\s*=\s*'[^']*'/s",
    '/@class\(\[.*?\]\)/s',
    '/(?<!:)\/\/[^\n]*/',
];

// A figure against the sign, in the three shapes a template writes one: after
// an echo, typed into the markup, or concatenated onto a value in PHP.
const PERCENT_SIGN_TYPED = '/(?:\}\}|!\}|\d)\s*%(?![sdu%])|\.\s*[\x27"]\s?%[\x27"]/u';

// Each entry names a file whose percent sign is not copy, and why. The `proves`
// pattern re-checks the reason: when it stops matching, the exemption has
// outlived what earned it.
const PERCENT_SIGN_PINS = [
    'Modules/Reports/Resources/views/livewire/partials/report-bar-chart.blade.php' => [
        'reason' => 'a bar geometry handed to the chart library: the width of a column against its slot, which no reader is shown',
        'proves' => "/'columnWidth' => '\d+%'/",
    ],
];

// Templates, and not the catalogues beside them: a catalogue spells the sign
// its own locale's way and the rule above judges it for that. The PHP that
// formats a figure reaches a reader through Fmt, and the only other `%` in
// module source is a LIKE wildcard, which is not a percent sign at all.
/** @return list<string> every template the product renders */
function percentSignTypedFiles(): array
{
    $files = [];

    foreach (Finder::create()->files()->in([base_path('Modules'), base_path('resources/views')])
        ->name('*.blade.php')->notPath('tests') as $file) {
        $files[] = $file->getRealPath();
    }

    sort($files);

    return $files;
}

function percentSignWithoutMachinery(string $source): string
{
    return PatternScan::replace(PERCENT_SIGN_MACHINERY, '', $source);
}

/** @return list<int> the line of every percent sign typed against a figure in $source */
function percentSignTypedLines(string $source): array
{
    $stripped = percentSignWithoutMachinery($source);
    $lines = [];

    foreach (PatternScan::setsWithOffsets(PERCENT_SIGN_TYPED, $stripped) as $match) {
        $lines[] = substr_count(substr($stripped, 0, $match[0][1]), "\n") + 1;
    }

    return $lines;
}

// Twelve readers of twenty-six were shown the right one by accident, and the
// same list of thresholds was declared twice: fi's catalogue said "±1 %"
// because this guard forces it, and the template beside it rendered "±1%".
it('never lets a template type the percent sign itself', function (): void {
    $offenders = [];
    $read = 0;

    foreach (percentSignTypedFiles() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $source = (string) file_get_contents($path);
        $read++;

        $pin = PERCENT_SIGN_PINS[$relative] ?? null;

        if ($pin !== null && PatternScan::matches($pin['proves'], $source)) {
            continue;
        }

        foreach (percentSignTypedLines($source) as $line) {
            $offenders[] = $relative.':'.$line;
        }
    }

    expect($read)->toBeGreaterThan(
        PERCENT_SIGN_FILE_FLOOR,
        'The reader opened '.$read.' files, which is what a walk that stopped reading looks like.'
    );

    expect($offenders)->toBe([], implode("\n", [
        'These write the percent sign into the markup, for all twenty-six readers at once:',
        ...$offenders,
        '',
        'No typed spelling is right for twenty-six locales: thirteen want a no-break',
        'space before the sign, Turkish wants it in front of the digits, and twelve',
        'want it closed up. Call Fmt::percent() and let the locale place it.',
    ]));
});

// The tree ships 285 templates, and a walk that stopped reading would report
// every one of them clean.
const PERCENT_SIGN_FILE_FLOOR = 250;

// The verdict above is read off one stripper and one pattern. Both are checked
// against the shapes they must tell apart rather than against the tree, so a
// rewrite cannot quietly stop finding them.
it('reads a typed percent sign, and leaves the machinery alone', function (string $source, array $lines): void {
    expect(percentSignTypedLines($source))->toBe($lines);
})->with([
    'an echo with the sign against it' => ['<span>{{ $pct }}%</span>', [1]],
    'an unescaped echo' => ['<span>{!! $pct !!}%</span>', [1]],
    'a figure typed into the markup' => ['<p>up 50% this month</p>', [1]],
    'a sign concatenated in PHP' => ["return \$pct.'%';", [1]],
    'a call that places it' => ['<span>{{ Fmt::percent($pct) }}</span>', []],
    'a CSS length in a style attribute' => ['<div style="width: 50%;"></div>', []],
    'a Tailwind arbitrary value' => ['<div class="h-[calc(100%-3rem)]"></div>', []],
    'a percent inside @class' => ["<div @class(['w-[50%]' => \$wide])></div>", []],
    'a printf specifier' => ['<p>%s of the budget</p>', []],
    'a sign named in a comment' => ['{{-- printed -0.0% once --}}', []],
    'a sign named in a PHP comment' => ['// printed -0.0% once', []],
    'a URL is not a comment' => ['<a href="https://example.test/a">{{ $pct }}%</a>', [1]],
]);

// Fmt::percent() and the guard below read the same table, so a mistake in it
// would be invisible: the tree would agree with itself and be wrong in thirteen
// languages at once. ICU is asked separately, and has to agree with all 26.
it('transcribes the percent convention CLDR gives, for every locale', function (): void {
    $conventions = percentSignConventions();

    expect(array_unique(array_values($conventions)))
        ->toHaveCount(3, 'ICU returned one convention for every locale — its locale data is missing.');

    expect(percentSignTranscribed())->toBe($conventions, implode("\n", [
        'Modules/Core/Public/Enums/Locale.php has drifted from CLDR.',
        'percentSignBeforeDigits() and percentGap() are transcribed because the',
        'phone ships English-only ICU data and cannot be asked on device.',
    ]));
});

it('spells a percent sign the way each locale spells it', function (): void {
    $conventions = percentSignConventions();

    // English-only ICU data reports every locale the same way, which would let
    // this walk pass a tree it never really read. The shipped set spans all
    // three conventions, so anything less means the data is not there.
    expect(array_unique(array_values($conventions)))
        ->toHaveCount(3, 'ICU returned one convention for every locale — its locale data is missing.');

    $offenders = [];
    $cells = 0;

    foreach (percentSignCatalogs() as $path) {
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
        'The reader found '.$cells.' percent signs across '.count(percentSignCatalogs())
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
    'closed up' => ['42% of the budget', ['GAP:']],
    'a no-break space' => ["42\u{00a0}% du budget", ['GAP:c2a0']],
    'a plain space is not the same spelling' => ['42 % van het budget', ['GAP:20']],
    'a narrow no-break space' => ["42\u{202f}% du budget", ['GAP:e280af']],
    'written in front' => ['%42 av budsjettet', ['PREFIX']],
    'a placeholder against the sign' => [':share% of the budget', ['GAP:']],
    'a printf string specifier' => ['%s of the budget', []],
    'a bare sign in prose' => ['the % key', []],
    'two signs in one line' => ["42% and 7\u{00a0}%", ['GAP:', 'GAP:c2a0']],
]);
