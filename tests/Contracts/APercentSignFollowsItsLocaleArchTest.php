<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\BladePhpSource;
use Modules\Core\Public\Support\MarkupSource;
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

// A sign a reader is shown is one in the text: after an echo, or typed into the
// markup beside a figure. A CSS length, a Tailwind class and a chart geometry
// are percentages of a box, and they live in attributes and in code, which is
// why the text is read rather than the file.
const PERCENT_SIGN_TYPED = '/(?:\}\}|!\}|\d)\h*%(?![sdu%])/u';

// The other half is code building the string: a sign appended to a value. A
// literal '55%' handed to a chart is not that, and is left alone for the same
// reason a CSS length is.
const PERCENT_SIGN_APPENDED = '/\.\h*[\x27"]\h?%[\x27"]/u';

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

// Where the run a reading matched sits in the file it was read out of. The
// window is widened until it names one place, because "}}%" alone names every
// echo with a sign against it.
/** @param array{0: string, 1: int} $match */
function percentSignSourceOffset(string $source, string $reading, array $match): ?int
{
    $end = $match[1] + strlen($match[0]);

    for ($width = strlen($match[0]); $width <= 120; $width += 12) {
        $needle = substr($reading, max(0, $end - $width), min($width, $end));
        $at = strpos($source, $needle);

        if ($at === false) {
            return null;
        }

        if (strpos($source, $needle, $at + 1) === false) {
            return $at + strlen($needle);
        }
    }

    return null;
}

// Blade is not HTML and a template is not PHP: an HTML5 parser relocates an
// <x-core::th> out of its table, and token_get_all reads a whole template as
// one T_INLINE_HTML. Both readings come from the parsers written for that,
// which keep the line the Blade wrote each one on.
/** @return list<int> the line of every percent sign a reader is shown in $source */
function percentSignTypedLines(string $source): array
{
    $lines = [];

    // BladePhpSource keeps the line the Blade wrote each island on. The text
    // reading does not: dropping a multi-line attribute takes its newlines with
    // it, and a line number counted there names a line in no file. The matched
    // run is copied verbatim out of the source, so it is looked up there.
    $readings = [
        [MarkupSource::text($source), PERCENT_SIGN_TYPED, true],
        [BladePhpSource::of($source), PERCENT_SIGN_APPENDED, false],
    ];

    foreach ($readings as [$reading, $pattern, $relocate]) {
        foreach (PatternScan::setsWithOffsets($pattern, $reading) as $match) {
            $at = $relocate
                ? percentSignSourceOffset($source, $reading, $match[0])
                : $match[0][1];

            $lines[] = $at === null ? 0 : substr_count(substr($source, 0, $at), "\n") + 1;
        }
    }

    sort($lines);

    return array_values(array_unique($lines));
}

// Twelve readers of twenty-six were shown the right one by accident, and the
// same list of thresholds was declared twice: fi's catalogue said "±1 %"
// because this guard forces it, and the template beside it rendered "±1%".
it('never lets a template type the percent sign itself', function (): void {
    $offenders = [];
    $read = 0;

    foreach (percentSignTypedFiles() as $path) {
        $read++;

        foreach (percentSignTypedLines((string) file_get_contents($path)) as $line) {
            $offenders[] = str_replace(base_path().'/', '', $path).':'.$line;
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
    'a sign appended in an island' => ["@php\n\$x = \$pct.'%';\n@endphp", [2]],
    'a call that places it' => ['<span>{{ Fmt::percent($pct) }}</span>', []],
    'a CSS length in a style attribute' => ['<div style="width: 50%;"></div>', []],
    'a rule in a style block' => ['<style>.a { width: 55%; }</style>', []],
    'a Tailwind arbitrary value' => ['<div class="h-[calc(100%-3rem)]"></div>', []],
    'a percent inside @class' => ["<div @class(['w-[50%]' => \$wide])></div>", []],
    'a geometry handed to a chart' => ["@php\n\$o = ['columnWidth' => '55%'];\n@endphp", []],
    'a printf specifier' => ['<p>%s of the budget</p>', []],
    'a sign named in a comment' => ['{{-- printed -0.0% once --}}', []],
    'a URL is not a comment' => ['<a href="https://example.test/a">{{ $pct }}%</a>', [1]],
    'a bare unit label beside a field' => ['<input><span>%</span>', []],
    'an echo a paragraph above a bare sign' => ["<p>{{ \$x }}</p>\n<span>%</span>", []],
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
