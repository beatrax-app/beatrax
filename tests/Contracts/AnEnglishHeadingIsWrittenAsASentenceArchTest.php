<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// A hundred and forty-five English headings are written as sentences and six were
// written as titles, two of them on one screen: "Data & Devices" over "App lock".
// The nav labels are in scope because a test already requires /drift's title and
// its nav item to be the same string, so a rule covering one has to cover both.

/**
 * Names, not words: the only thing allowed to keep a capital away from the front
 * of a heading. Add a genuine name here; a common noun belongs in lower case.
 * Every entry is held to excusing a real word below, so a name the product has
 * stopped writing is deleted rather than left reading as considered.
 *
 * @return list<string>
 */
function headingProperNouns(): array
{
    return ['Beatrax', 'Console', 'Google', 'ICS', 'PayPal', 'Play', 'YNAB'];
}

/**
 * The words a heading capitalises away from the front of a phrase, split into
 * the ones no name excuses and the names that did the excusing.
 *
 * @param  list<string>  $names
 * @return array{titled: list<string>, excused: list<string>}
 */
function englishHeadingTitleCasedWords(string $value, array $names): array
{
    // A separator starts a new phrase, so the word after one is at the
    // front of its own label: "Queue :queue · Worker :worker" is two.
    $words = PatternScan::split('/\s+/', trim($value));
    $opensPhrase = true;
    $titled = [];
    $excused = [];

    foreach ($words as $word) {
        $wasOpening = $opensPhrase;
        $opensPhrase = preg_match('/[·—–:|\/]$/u', $word) === 1;
        $word = trim($word, '.,:;·—–-()"\'?!');

        if ($wasOpening || $word === '' || preg_match('/^\p{Lu}/u', $word) !== 1) {
            continue;
        }

        if (in_array($word, $names, true)) {
            $excused[] = $word;

            continue;
        }

        $titled[] = $word;
    }

    return ['titled' => $titled, 'excused' => $excused];
}

/**
 * The whole walk: what reads as a title, which names kept a word out of that
 * list, and how many headings were read at all.
 *
 * @return array{titled: list<string>, excused: array<string, int>, headings: int}
 */
function englishHeadingScan(): array
{
    $names = headingProperNouns();
    $titled = [];
    $excused = array_fill_keys($names, 0);
    $headings = 0;

    foreach (glob(base_path('Modules/*/Resources/lang/en/*.php')) ?: [] as $file) {
        foreach (englishHeadings($file) as $key => $value) {
            $headings++;
            $read = englishHeadingTitleCasedWords($value, $names);

            foreach ($read['excused'] as $name) {
                $excused[$name]++;
            }

            foreach ($read['titled'] as $word) {
                $titled[] = str_replace(base_path().'/', '', $file)." [{$key}] \"{$value}\" capitalises \"{$word}\"";
            }
        }
    }

    sort($titled);

    return ['titled' => $titled, 'excused' => $excused, 'headings' => $headings];
}

/**
 * @return array<string, string>
 */
function englishHeadings(string $file): array
{
    /** @var array<array-key, mixed> $loaded */
    $loaded = require $file;

    $walk = static function (array $node, string $prefix) use (&$walk): array {
        $out = [];
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $out += $walk($value, $path);

                continue;
            }
            if (preg_match('/(^|\.)(heading|page_title|title)$|^nav\./', $path) === 1) {
                $out[$path] = (string) $value;
            }
        }

        return $out;
    };

    return $walk($loaded, '');
}

it('writes an English heading as a sentence, the way a hundred and forty-five of them do', function (): void {
    $scan = englishHeadingScan();

    // Two hundred and seventy headings stand under these keys today. A glob
    // that answered nothing would report every one of them as a sentence.
    expect($scan['headings'])->toBeGreaterThan(
        100,
        'Read '.$scan['headings'].' English headings, too few for an empty offender list to mean anything.',
    );

    expect($scan['titled'])->toBe(
        [],
        'These English headings are written as titles while the rest of the product writes '
        ."sentences:\n  ".implode("\n  ", $scan['titled'])
    );
});

// A name nothing writes any more excuses nothing, and an allow-list nobody
// re-checks is how the next common noun gets in beside it.
it('keeps no proper noun that no heading writes', function (): void {
    $unused = array_keys(array_filter(
        englishHeadingScan()['excused'],
        static fn (int $hits): bool => $hits === 0,
    ));

    expect($unused)->toBe([], implode("\n", [
        'These names are allowed to keep a capital mid-heading and no heading uses them: '
        .implode(', ', $unused),
        '',
        'Two entries were deleted for exactly this: "Actual" and "Dev" both sit at the',
        'front of their phrase — "Import from YNAB / Actual" opens a new one after the',
        'separator — so the rule already let them through and the entries excused nothing.',
        'Delete the name, or write the heading that needs it in the same commit.',
    ]));
});

it('reads a heading written as a title, and leaves a sentence and a name alone', function (): void {
    $names = headingProperNouns();

    expect(englishHeadingTitleCasedWords('Data & Devices', $names)['titled'])
        ->toBe(['Devices'], 'the mid-phrase capital is the whole defect, and the scan has to find it');

    expect(englishHeadingTitleCasedWords('App lock', $names)['titled'])
        ->toBe([], 'a heading written as a sentence is what a hundred and forty-five of them do');

    expect(englishHeadingTitleCasedWords('Import from YNAB', $names))
        ->toBe(['titled' => [], 'excused' => ['YNAB']], 'a name keeps its capital, and says which name did the excusing');

    expect(englishHeadingTitleCasedWords('Queue :queue · Worker :worker', $names)['titled'])
        ->toBe([], 'a separator starts a new phrase, so the word after it is at the front of its own label');
});
