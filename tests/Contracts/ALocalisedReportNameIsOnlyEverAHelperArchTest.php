<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// PayPal names its exports in the account holder's own language, so "Rapport
// Transactiegegevens" is what a Dutch account calls the report and nothing a
// Belgian, German or Estonian reader will ever see on their screen. The wizard
// told all twenty-six to go and find it by that name. The lede tagged it
// lang="nl" and so read correctly aloud; the three error strings repeated it as
// bare text, where a screen reader pronounces Dutch in the reader's own accent
// and the reader is sent hunting for a menu entry that is not there.
//
// The rule is not "never write it": it is the export this app parses today, and
// naming it is how a reader confirms they have the right file. It may only
// appear as a helper beside the report's description, inside markup that says
// which language it is.

/**
 * The report names PayPal writes in Dutch, and nothing else does.
 *
 * @return list<string>
 */
function dutchReportNames(): array
{
    return ['Rapport Transactiegegevens', 'Saldorapport'];
}

/**
 * @return array<string, string> key path => line
 */
function wizardLinesIn(string $file): array
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
            $out[$path] = (string) $value;
        }

        return $out;
    };

    return $walk($loaded, '');
}

/** the line with every lang="nl"-tagged element removed, which is what a reader hears untagged */
function outsideDutchMarkup(string $line): string
{
    return PatternScan::replace('/<([a-z]+)[^>]*\blang="nl"[^>]*>.*?<\/\1>/su', '', $line);
}

// The walk is the wizard's own lang files, which is where the defect shipped
// and what the description says. HeaderSniffer raises two English exception
// messages naming both reports; they are outside this rule and are reported
// rather than swept in, because widening the walk to production strings is a
// change to those strings, not to this guard.
it('never asks a reader to look for a Dutch report name in a wizard line without saying it is Dutch', function (): void {
    $offenders = [];
    $lines = 0;

    foreach (glob(base_path('Modules/Onboarding/Resources/lang/*/*.php')) ?: [] as $file) {
        foreach (wizardLinesIn($file) as $key => $line) {
            $lines++;

            $bare = outsideDutchMarkup($line);
            foreach (dutchReportNames() as $name) {
                if (! str_contains($bare, $name)) {
                    continue;
                }

                $offenders[] = str_replace(base_path().'/', '', $file)." [{$key}] names \"{$name}\" untagged";
            }
        }
    }

    sort($offenders);

    // Five thousand seven hundred wizard lines ship across twenty-six locales.
    // A glob that answered nothing would report every one of them as tagged.
    expect($lines)->toBeGreaterThan(1000, 'Read '.$lines.' wizard lines, too few for an empty offender list to mean anything.');

    expect($offenders)->toBe([], implode("\n  ", [
        'A PayPal report name written in Dutch reached a reader with nothing saying so.',
        'Describe the report by what it is — the per-transaction activity export, not the',
        'balance summary — and keep the Dutch name as a helper inside lang="nl" markup.',
        'A plain-text line (an error message) cannot carry markup, so it must not carry',
        'the name either. Offenders:',
        ...$offenders,
    ]));
});

it('reads a report name written outside the markup that says it is Dutch, and leaves a tagged one alone', function (): void {
    $tagged = 'Kies <em lang="nl">Rapport Transactiegegevens</em>, niet <span lang="nl">Saldorapport</span>.';
    $bare = 'Download the Rapport Transactiegegevens export before you continue.';

    expect(str_contains(outsideDutchMarkup($tagged), 'Rapport Transactiegegevens'))
        ->toBeFalse('a name inside lang="nl" markup is announced as Dutch, which is the whole allowance');

    expect(str_contains(outsideDutchMarkup($tagged), 'Saldorapport'))
        ->toBeFalse('the second tagged element is stripped too, or one strip would answer for both');

    expect(str_contains(outsideDutchMarkup($bare), 'Rapport Transactiegegevens'))
        ->toBeTrue('an untagged name is the defect, and the strip has to leave it where the scan can see it');
});
