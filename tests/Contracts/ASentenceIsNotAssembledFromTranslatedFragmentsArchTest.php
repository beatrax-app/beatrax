<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Symfony\Component\Finder\Finder;

// A sentence split into prefix + variable + suffix keys can only be reassembled
// in the order the splitter's own language puts them. The doctor panel's empty
// state was three keys with the command name appended last, so every locale had
// to end the sentence with the command whether or not its grammar allows it:
//
//   nl  "om aan te roepen beatrax:doctor"   (the verb belongs after the object)
//   et  "et see käivitada beatrax:doctor"   (names the command twice)
//   fi  "käynnistääksesi sen beatrax:doctor"          likewise
//   lv  "lai to izsauktu beatrax:doctor"               likewise
//
// One key with a placeholder can be reordered per language, which is the only
// shape that lets a translator put the object where their language wants it.

// The rule the doctor panel taught, read over the whole tree rather than over
// the three keys that shipped it. An email-scan tile appended a relative time
// to "last scanned" and the inboxes page did the same, so no locale could put
// the time anywhere but last — the shape the four rows above could not survive.

// A middot joins two independent labels rather than continuing a sentence, and
// the tree uses it that way throughout: Brand::TITLE_SUFFIX is ' · ' and the
// product name, SearchDocumentBody::DISPLAY_SEPARATOR is the same glyph. No
// language reorders "All accounts · Baseline"; it is two labels, not a clause.
const SENTENCE_ASSEMBLY_COMPOUND = '/^\s*\.\s*Brand::TITLE_SUFFIX/';

const SENTENCE_ASSEMBLY_COMPOUND_SEPARATOR = '/\x{00B7}/u';

// Prose is stripped before the scan, or a comment naming the shape reads as the
// shape. The lookbehind keeps a URL's // out of it.
const SENTENCE_ASSEMBLY_PROSE = [
    '/\{\{--.*?--\}\}/s',
    '/(?<!:)\/\/[^\n]*/',
    '/\/\*.*?\*\//s',
];

/** @return list<string> the PHP and the templates the product ships, catalogues aside */
function sentenceAssemblyFiles(): array
{
    $files = [];

    foreach (Finder::create()->files()->in(base_path('Modules'))->name('*.php')
        ->notPath('tests')->notPath('Resources/lang') as $file) {
        $files[] = $file->getRealPath();
    }

    sort($files);

    return $files;
}

/** The offset just past the ")" closing the call whose "(" is at $open. */
function sentenceAssemblyCallEnd(string $source, int $open): ?int
{
    $depth = 0;
    $length = strlen($source);

    for ($i = $open; $i < $length; $i++) {
        if ($source[$i] === '(') {
            $depth++;
        } elseif ($source[$i] === ')') {
            $depth--;

            if ($depth === 0) {
                return $i + 1;
            }
        }
    }

    return null;
}

/**
 * Every Lang::get() in $source with a non-literal concatenated onto it.
 *
 * @return list<array{line: int, tail: string}>
 */
function sentenceAssemblies(string $source, int &$calls): array
{
    // Each comment is replaced by its own newlines rather than removed, or the
    // line a reader is sent to is the line in a file that does not exist.
    foreach (SENTENCE_ASSEMBLY_PROSE as $prose) {
        $source = PatternScan::replaceCallback(
            $prose,
            static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $source,
        );
    }
    $found = [];
    $offset = 0;

    while (($at = strpos($source, 'Lang::get(', $offset)) !== false) {
        $calls++;
        $end = sentenceAssemblyCallEnd($source, $at + strlen('Lang::get'));
        $offset = $end ?? $at + strlen('Lang::get(');

        if ($end === null) {
            continue;
        }

        $tail = substr($source, $end, 200);

        // Separators the call site owns are stepped over rather than accepted:
        // `Lang::get(...).' '.$when` puts a literal between the line and the
        // value, and reading only the first operand would pass it. A middot
        // among them says the two sides are separate labels, and stops the walk.
        $beyond = $tail;
        $compound = PatternScan::matches(SENTENCE_ASSEMBLY_COMPOUND, $tail);

        while (! $compound) {
            $separator = PatternScan::first('/^\s*\.\s*(\x27[^\x27]*\x27|"[^"]*")/', $beyond);

            if ($separator === []) {
                break;
            }

            $compound = PatternScan::matches(SENTENCE_ASSEMBLY_COMPOUND_SEPARATOR, $separator[1]);
            $beyond = substr($beyond, strlen($separator[0]));
        }

        if (! $compound && PatternScan::matches('/^\s*\.\s*(?![\x27"])\S/', $beyond)) {
            $found[] = [
                'line' => substr_count(substr($source, 0, $at), "\n") + 1,
                'tail' => trim(PatternScan::replace('/\s+/', ' ', substr($tail, 0, 40))),
            ];
        }
    }

    return $found;
}

// The tree resolves a key four thousand times, and a walk that stops reading
// finds no assembly among them and calls the tree clean.
const SENTENCE_ASSEMBLY_CALL_FLOOR = 3000;

it('never appends a value to a translated line', function (): void {
    $offenders = [];
    $calls = 0;

    foreach (sentenceAssemblyFiles() as $path) {
        foreach (sentenceAssemblies((string) file_get_contents($path), $calls) as $assembly) {
            $offenders[] = str_replace(base_path().'/', '', $path).':'.$assembly['line'].'  '.$assembly['tail'];
        }
    }

    expect($calls)->toBeGreaterThan(
        SENTENCE_ASSEMBLY_CALL_FLOOR,
        'The reader found '.$calls.' Lang::get calls, which is what a walk that stopped reading looks like.'
    );

    expect($offenders)->toBe([], implode("\n", [
        'These append a value to a line a translator can only receive whole:',
        ...$offenders,
        '',
        'Put the value in the line as a placeholder and pass it to Lang::get(),',
        'so a locale can move it. Appending fixes it last in all twenty-six.',
    ]));
});

// The verdict above is read off one scan, and a scan that stopped matching would
// report the tree clean. It is checked against the shapes it has to tell apart.
it('reads an appended value, and leaves a separator and a tab title alone', function (string $source, int $expected): void {
    $calls = 0;

    expect(sentenceAssemblies($source, $calls))->toHaveCount($expected);
})->with([
    'a relative time appended' => ["Lang::get('a.b').\$when", 1],
    'appended through a call' => ["Lang::get('a.b').CarbonImmutable::now()->diffForHumans()", 1],
    'a list appended' => ["Lang::get('a.b').implode(', ', \$parts)", 1],
    'a separator the call site owns' => ["Lang::get('a.b').': '", 0],
    'a value behind a separator' => ["Lang::get('a.b').' '.\$when", 1],
    'a value behind a separator on the next line' => ["Lang::get('a.b').' '\n    .\$when", 1],
    'two translated fragments joined' => ["Lang::get('a.b').' '.Lang::get('a.c')", 1],
    'a placeholder passed in' => ["Lang::get('a.b', ['when' => \$when])", 0],
    'a tab title' => ["Lang::get('a.page_title').Brand::TITLE_SUFFIX", 0],
    'two labels joined by a middot' => ["Lang::get('a.b').' \u{00B7} '.Lang::get('a.c')", 0],
    'a middot compound with a value' => ["Lang::get('a.b').' \u{00B7} '.\$name", 0],
    'a line named in prose' => ["// Lang::get('a.b').\$when is the shape", 0],
    'a line named in a template comment' => ["{{-- Lang::get('a.b').\$when --}}", 0],
]);

/** @return array<string, array<string, string>> locale => flattened doctor strings */
function doctorStringsPerLocale(): array
{
    $root = base_path('Modules/DevMode/Resources/lang');
    $out = [];

    foreach ((new Finder)->directories()->in($root)->depth(0) as $dir) {
        $file = $dir->getPathname().'/doctor.php';

        if (! is_file($file)) {
            continue;
        }

        /** @var array<string, string> $strings */
        $strings = require $file;
        $out[$dir->getFilename()] = $strings;
    }

    return $out;
}

it('carries the doctor empty state as one sentence in every locale it ships', function (): void {
    $locales = doctorStringsPerLocale();

    expect(count($locales))->toBeGreaterThan(20, 'the walk found almost no locales, so a clean answer below means nothing');

    $broken = [];

    foreach ($locales as $locale => $strings) {
        foreach (['empty_prefix', 'empty_rerun', 'empty_suffix'] as $fragment) {
            if (array_key_exists($fragment, $strings)) {
                $broken[] = $locale.' still carries '.$fragment;
            }
        }

        $sentence = $strings['empty_html'] ?? null;

        if (! is_string($sentence)) {
            $broken[] = $locale.' has no empty_html';

            continue;
        }

        foreach ([':action', ':command'] as $placeholder) {
            if (! str_contains($sentence, $placeholder)) {
                $broken[] = $locale.' drops '.$placeholder;
            }
        }
    }

    expect($broken)->toBe([], implode("\n  ", array_merge(
        ['The empty state is one sentence with two placeholders, per locale:'],
        $broken,
    )));
});

// The control: if every locale still ended on the command, the shape above
// would be the old defect with new key names. At least one language has to be
// putting something after it, or nothing was actually freed.
it('lets a locale put words after the command, which the appended form could not', function (): void {
    $after = [];

    foreach (doctorStringsPerLocale() as $locale => $strings) {
        $sentence = $strings['empty_html'] ?? '';
        $tail = trim(substr($sentence, (int) strpos($sentence, ':command') + strlen(':command')), " \t.");

        if ($tail !== '') {
            $after[$locale] = $tail;
        }
    }

    expect($after)->not->toBe([], 'every locale still ends on the command, so the placeholder bought nothing');
    expect($after)->toHaveKey('nl');
    expect($after['nl'])->toBe('aan te roepen');
});

// The word the sentence tells the reader to press and the word on the button
// are one key, so a translator cannot change one and leave the other.
it('names the button by the button\'s own key', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/DevMode/Resources/views/livewire/doctor-panel-page.blade.php'),
    );

    expect($blade)->toContain("'action' => '<span class=\"font-semibold\">'.e(Lang::get('dev::doctor.rerun')).'</span>'")
        ->and($blade)->not->toContain('empty_prefix');
});
