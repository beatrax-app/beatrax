<?php

declare(strict_types=1);

// A hundred and forty-five English headings are written as sentences and six were
// written as titles, two of them on one screen: "Data & Devices" over "App lock".
// The nav labels are in scope because a test already requires /drift's title and
// its nav item to be the same string, so a rule covering one has to cover both.

/**
 * Names, not words: the only thing allowed to keep a capital away from the front
 * of a heading. Add a genuine name here; a common noun belongs in lower case.
 *
 * @return list<string>
 */
function headingProperNouns(): array
{
    return ['Actual', 'Beatrax', 'Console', 'Dev', 'ICS', 'PayPal', 'YNAB'];
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
    $titled = [];
    $names = headingProperNouns();

    foreach (glob(base_path('Modules/*/Resources/lang/en/*.php')) ?: [] as $file) {
        foreach (englishHeadings($file) as $key => $value) {
            // A separator starts a new phrase, so the word after one is at the
            // front of its own label: "Queue :queue · Worker :worker" is two.
            $words = preg_split('/\s+/', trim($value)) ?: [];
            $opensPhrase = true;

            foreach ($words as $word) {
                $wasOpening = $opensPhrase;
                $opensPhrase = preg_match('/[·—–:|\/]$/u', $word) === 1;
                $word = trim($word, '.,:;·—–-()"\'?!');

                if ($wasOpening || $word === '' || preg_match('/^\p{Lu}/u', $word) !== 1
                    || in_array($word, $names, true)) {
                    continue;
                }

                $titled[] = str_replace(base_path().'/', '', $file)." [{$key}] \"{$value}\" capitalises \"{$word}\"";
            }
        }
    }

    sort($titled);

    expect($titled)->toBe(
        [],
        'These English headings are written as titles while the rest of the product writes '
        ."sentences:\n  ".implode("\n  ", $titled)
    );
});
