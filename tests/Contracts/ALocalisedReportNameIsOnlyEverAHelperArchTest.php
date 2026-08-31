<?php

declare(strict_types=1);

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
    return (string) preg_replace('/<([a-z]+)[^>]*\blang="nl"[^>]*>.*?<\/\1>/su', '', $line);
}

it('never asks a reader to look for a Dutch report name without saying it is Dutch', function (): void {
    $offenders = [];

    foreach (glob(base_path('Modules/Onboarding/Resources/lang/*/*.php')) ?: [] as $file) {
        foreach (wizardLinesIn($file) as $key => $line) {
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

    expect($offenders)->toBe([], implode("\n  ", [
        'A PayPal report name written in Dutch reached a reader with nothing saying so.',
        'Describe the report by what it is — the per-transaction activity export, not the',
        'balance summary — and keep the Dutch name as a helper inside lang="nl" markup.',
        'A plain-text line (an error message) cannot carry markup, so it must not carry',
        'the name either. Offenders:',
        ...$offenders,
    ]));
});
