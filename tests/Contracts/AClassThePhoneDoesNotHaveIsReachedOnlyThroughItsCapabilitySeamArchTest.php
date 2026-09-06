<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 * @link ../../.docs/features/migration/reading-a-zip-without-ext-zip.md
 */

// The NativePHP mobile PHP build carries no ext-zip: its php_config.h has
// `#undef HAVE_ZIP` on both iOS and Android. `new ZipArchive` there is not a
// caught failure, it is a bare PHP Error, and the screen that caught it told
// the reader their perfectly valid export was unreadable. Reading was the first
// direction to need a seam; writing one needed a second, so each pair below is
// a factory and the extension-backed half it picks.
//
// Each seam states what earns it the name, and `proves` is re-run against the
// file: a bare path would let the same class pick up an unguarded `new` in a
// second method, which is the failure the seam exists to make impossible.
const PHONELESS_CLASS_SEAMS = [
    'ZipArchive' => [
        'Modules/Migration/Internal/Parsers/Support/ZipArchiveReader.php' => [
            'reason' => 'the extension-backed reader itself, reached only through the factory below',
            'proves' => '/final class ZipArchiveReader implements ArchiveReader/',
        ],
        'Modules/Migration/Internal/Parsers/Support/ArchiveReaderFactory.php' => [
            'reason' => 'the reading seam: it asks whether the running build carries the extension before it picks the reader that needs one',
            'proves' => '/class_exists\(ZipArchive::class\)/',
        ],
        'Modules/Core/Internal/Backup/ZipArchiveWriter.php' => [
            'reason' => 'the extension-backed writer itself, reached only through the factory below',
            'proves' => '/final class ZipArchiveWriter implements ArchiveWriter/',
        ],
        'Modules/Core/Internal/Backup/ArchiveWriterFactory.php' => [
            'reason' => 'the writing seam: the same question asked before the writer that needs the extension is chosen',
            'proves' => '/class_exists\(ZipArchive::class\)/',
        ],
        'Modules/Mobile/Internal/Boot/ShippedBundleContents.php' => [
            'reason' => 'reads a built artifact on the machine that built it, and asks the same question first: on a build with no ext-zip it reports that the artifact was never read rather than reporting it clean',
            'proves' => '/class_exists\(ZipArchive::class\)/',
        ],
    ],
];

/**
 * The PHP that ships. Read from RepoTree rather than from a hand-written pair
 * of roots: the rule claims a class is named nowhere but its seam, and Modules
 * and app said nothing about scripts/, config/ or either shell's bootstrap.
 *
 * @return list<string> absolute paths
 */
function phonelessClassScannedFiles(): array
{
    return RepoTree::files(RepoTree::PRODUCTION_PHP);
}

it('reaches a class the phone build does not carry only through its capability seam', function (): void {
    $files = phonelessClassScannedFiles();

    // Far under the ~6,700 the tree holds, so a walk that opened nothing fails
    // here rather than reporting a tree that names the class nowhere.
    expect(count($files))->toBeGreaterThan(
        2000,
        'The walk opened '.count($files).' shipped PHP files, which is too few to have read the tree at all.',
    );

    $offenders = [];
    $reached = [];

    foreach ($files as $path) {
        $relative = str_replace(RepoTree::root().'/', '', $path);
        $source = (string) file_get_contents($path);

        foreach (PHONELESS_CLASS_SEAMS as $class => $seams) {
            if (! PatternScan::matches('/\b'.preg_quote($class, '/').'\b/', $source)) {
                continue;
            }

            if (isset($seams[$relative])) {
                $reached[$class][$relative] = true;

                continue;
            }

            $offenders[] = "{$relative} names {$class}";
        }
    }

    expect($offenders)->toBe(
        [],
        "A class the mobile PHP build does not carry was named outside the files allowed to ask for it.\n".
        "On a phone that line raises a bare PHP Error, not a typed failure, and whatever catch is above it\n".
        "reports a fault of ours as a fault of the reader's file. Reach it through ArchiveReaderFactory,\n".
        "which picks a reader the running build actually has, or add the new seam file to the list above:\n  ".
        implode("\n  ", $offenders),
    );

    // A seam nothing reaches any more is a claim about the tree that stopped
    // being true, and it would otherwise sit here forever.
    foreach (PHONELESS_CLASS_SEAMS as $class => $seams) {
        $found = array_keys($reached[$class] ?? []);
        $declared = array_keys($seams);
        sort($found);
        sort($declared);

        expect($found)->toBe(
            $declared,
            $class.': a file is listed as a seam and no longer names the class at all. Delete the entry rather '
            .'than leave a waiver standing over a file the rule would otherwise be reading.',
        );
    }
});

it('still holds each seam to the reason it was granted for', function (): void {
    $broken = [];

    foreach (PHONELESS_CLASS_SEAMS as $class => $seams) {
        foreach ($seams as $relative => $seam) {
            $path = RepoTree::root().'/'.$relative;

            if (! is_file($path)) {
                $broken[] = $relative.' is named as the '.$class.' seam and no longer exists';

                continue;
            }

            if (! PatternScan::matches($seam['proves'], (string) file_get_contents($path))) {
                $broken[] = $relative.' is exempt because "'.$seam['reason'].'", and it no longer reads that way';
            }
        }
    }

    expect($broken)->toBe([], implode("\n  ", [
        'An exemption whose reason no longer holds is a gap nobody chose:',
        ...$broken,
    ]));
});
