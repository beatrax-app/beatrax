<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Plaintext is staged in a private directory, never the shared temp dir.
 *
 * Four places in this codebase state the rule in a comment — the backup
 * download, the GDK keyring, and both device-identity writers all say NEVER
 * sys_get_temp_dir(), because /tmp is mode 1777 and anyone on the machine can
 * traverse it. Nothing checked, and the restore path did exactly that: it
 * decrypted the whole database — every transaction the user has — to
 * /tmp/beatrax-restore-*.sqlite through a plain fopen, which lands at 0644.
 *
 * Even 0600 is not enough there. tempnam() protects the CONTENT but not the
 * name or the size, and an uploaded bank statement leaks something by both.
 * So there is no allow-list in production code: a 0700 directory under the
 * app's own storage costs three lines, which is cheaper than deciding case by
 * case which secrets may sit in a world-readable directory.
 *
 * Test files are out of SCOPE rather than exempt. The rule protects user data
 * at rest on a real machine; a fixture mbox a test writes and deletes carries
 * nobody's money, and holding tests to it would only teach people that the
 * rule has exceptions.
 */

it('stages nothing in the shared temp directory', function (): void {
    $offenders = [];

    $finder = (new Finder)->files()->in([base_path('Modules'), base_path('app')])->name('*.php')->notPath('tests');

    foreach ($finder as $file) {
        foreach (explode("\n", $file->getContents()) as $number => $line) {
            // The rule is quoted in comments explaining it; those are the
            // point, not a violation.
            $code = trim($line);

            if (str_starts_with($code, '//') || str_starts_with($code, '*')) {
                continue;
            }

            if (str_contains($line, 'sys_get_temp_dir')) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([], implode("\n  ", array_merge(
        ['sys_get_temp_dir() is world-traversable (/tmp is 1777), so anything staged there',
            'leaks at least its name and size, and a plain fopen lands at 0644. Stage under a',
            '0700 directory instead: UserDataPathService::appPath(\'tmp-…\') + mkdir 0700. Offenders:'],
        $offenders,
    )));
});
