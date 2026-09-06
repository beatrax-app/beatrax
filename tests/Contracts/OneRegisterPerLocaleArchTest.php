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

/**
 * @param  array{formal: string, informal: string}  $pattern
 */
function registerMixedInSource(string $contents, array $pattern): bool
{
    return preg_match('/'.$pattern['formal'].'/u', $contents) === 1
        && preg_match('/'.$pattern['informal'].'/u', $contents) === 1;
}

it('does not address one reader two ways inside a single locale file', function (): void {
    $mixed = [];
    $read = 0;

    foreach (registerMarkers() as $locale => $pattern) {
        // Both homes a translated string has: the module trees, and the
        // top-level lang/ that carries the framework's own lines. A rule about
        // how a locale addresses its reader cannot stop at one of them.
        $files = [
            ...glob(base_path("Modules/*/Resources/lang/{$locale}/*.php")) ?: [],
            ...glob(base_path("lang/{$locale}/*.php")) ?: [],
        ];

        foreach ($files as $file) {
            $read++;

            if (registerMixedInSource((string) file_get_contents($file), $pattern)) {
                $mixed[] = str_replace(base_path().'/', '', $file);
            }
        }
    }

    sort($mixed);

    expect($read)->toBeGreaterThan(200, 'The locale glob matched almost nothing, so a clean answer below is the glob being broken rather than the locales being consistent.');

    expect($mixed)->toBe(
        [],
        "These locale files address the reader formally in one string and informally in another:\n  "
        .implode("\n  ", $mixed)
    );
});

it('reads a mixed register and leaves a consistent one alone', function (): void {
    $markers = registerMarkers()['hr'];

    $mixed = "<?php\n\nreturn ['a' => 'Vaši podaci', 'b' => 'Tvoji uređaji'];\n";
    $formal = "<?php\n\nreturn ['a' => 'Vaši podaci', 'b' => 'Vaši uređaji'];\n";
    $informal = "<?php\n\nreturn ['a' => 'Tvoji podaci', 'b' => 'Tvoji uređaji'];\n";

    expect(registerMixedInSource($mixed, $markers))->toBeTrue('The reader stopped seeing the two registers this rule was written for.')
        ->and(registerMixedInSource($formal, $markers))->toBeFalse('A file that is formal throughout is being reported as mixed.')
        ->and(registerMixedInSource($informal, $markers))->toBeFalse('A file that is informal throughout is being reported as mixed.');
});
