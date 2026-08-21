<?php

declare(strict_types=1);

// A locale decides once whether it addresses the reader formally, and every
// string in it has to keep that decision. Eight strings did not: one screen
// said "Vaši podaci" directly above a button reading "Tvoji uređaji", which
// reads as two people talking. It is invisible to a parity test, because both
// spellings are present and translated, and invisible to a reviewer who does
// not read the language — but not to the reader it is addressed to.

/**
 * The second-person possessive in both registers, for the languages that
 * inflect it. Only pairs whose two forms cannot both be correct in one file:
 * a language where the formal is the plural of the informal would flag every
 * legitimately-plural sentence.
 *
 * @return array<string, array{formal: string, informal: string}>
 */
function registerMarkers(): array
{
    return [
        'sk' => ['formal' => '\b(Vaše|Vaši|Vaša|Vašu)\b', 'informal' => '\b(Tvoje|Tvoji|Tvoja|Tvoju)\b'],
        'sl' => ['formal' => '\b(Vaše|Vaši|Vaša|Vašo)\b', 'informal' => '\b(Tvoje|Tvoji|Tvoja|Tvojo)\b'],
        'hr' => ['formal' => '\b(Vaše|Vaši|Vaša|Vašu)\b', 'informal' => '\b(Tvoje|Tvoji|Tvoja|Tvoju)\b'],
        'sr' => ['formal' => '\b(Vaše|Vaši|Vaša|Vašu)\b', 'informal' => '\b(Tvoje|Tvoji|Tvoja|Tvoju)\b'],
    ];
}

it('does not address one reader two ways inside a single locale file', function (): void {
    $mixed = [];

    foreach (registerMarkers() as $locale => $pattern) {
        foreach (glob(base_path("Modules/*/Resources/lang/{$locale}/*.php")) ?: [] as $file) {
            $contents = (string) file_get_contents($file);

            $formal = preg_match('/'.$pattern['formal'].'/u', $contents) === 1;
            $informal = preg_match('/'.$pattern['informal'].'/u', $contents) === 1;

            if ($formal && $informal) {
                $mixed[] = str_replace(base_path().'/', '', $file);
            }
        }
    }

    sort($mixed);

    expect($mixed)->toBe(
        [],
        "These locale files address the reader formally in one string and informally in another:\n  "
        .implode("\n  ", $mixed)
    );
});
