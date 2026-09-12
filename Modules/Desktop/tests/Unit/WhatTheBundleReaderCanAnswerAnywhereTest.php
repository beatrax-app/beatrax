<?php

declare(strict_types=1);

use Modules\Desktop\Internal\Boot\MacBundleReader;
use Symfony\Component\Process\Process;

// The reader is the half that talks to the machine, and most of what it does
// answers the same on any machine: a directory walk, a file-magic question,
// and — where codesign is absent — the refusal that means "nothing was read".
//
// Every assertion here used to be that the reader read NOTHING: two empty
// executable lists, an empty entitlement set, an unsigned plain file. All four
// pass just as well when the reader has stopped reading at all, and three of
// them did — narrowing the magic test to a string `file` no longer prints took
// a real bundle from five executables to zero with this file still green. Each
// arm now carries the other direction too.

// Thirty-two bytes is all `file` needs: magic, cputype, cpusubtype and a
// filetype of MH_EXECUTE. Built rather than shipped so the control costs no
// binary in the tree and cannot rot into a fixture nobody re-reads.
function aMachOExecutable(): string
{
    return pack('V', 0xFEEDFACF)       // MH_MAGIC_64
        .pack('V', 0x0100000C)         // CPU_TYPE_ARM64
        .pack('V', 0)                  // cpusubtype
        .pack('V', 2)                  // MH_EXECUTE
        .pack('V', 0)                  // ncmds
        .pack('V', 0)                  // sizeofcmds
        .pack('V', 0)                  // flags
        .pack('V', 0)                  // reserved
        .str_repeat("\0", 64);
}

function aToolNamed(string $tool): bool
{
    $process = new Process(['which', $tool]);
    $process->run();

    return $process->isSuccessful();
}

function readerUnderTest(): MacBundleReader
{
    return new MacBundleReader;
}

function aTreeOf(array $files): string
{
    $root = sys_get_temp_dir().'/beatrax-reader-'.bin2hex(random_bytes(6));

    foreach ($files as $relative => $contents) {
        $path = $root.'/'.$relative;
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0o700, true);
        }

        file_put_contents($path, $contents);
    }

    return $root;
}

it('reads a nested executable as inside a MacOS directory only where one is', function (string $relative, bool $inside): void {
    expect(readerUnderTest()->isInAMacOsDirectory($relative))->toBe($inside);
})->with([
    ['Contents/MacOS/Beatrax', true],
    ['Contents/Frameworks/Helper.app/Contents/MacOS/Helper', true],
    ['Contents/Resources/build/php/php', false],
    ['Contents/Frameworks/Squirrel.framework/Versions/A/Resources/ShipIt', false],
    // Prefixed rather than contained: a directory merely NAMED MacOS at the
    // top level is not the one a nested executable has to live in.
    ['MacOS/php', false],
]);

// The positive control for every "found nothing" below it. Without this, a
// reader that answers [] to everything passes this whole file.
it('finds the executable in a tree that holds one', function (): void {
    expect(aToolNamed('file'))->toBeTrue(
        'Without `file` the magic question cannot be asked at all, and "the tool is missing" would read here as "there are no executables".',
    );

    $root = aTreeOf([
        'Contents/MacOS/Beatrax' => aMachOExecutable(),
        'Contents/Info.plist' => '<plist/>',
        'Contents/Resources/notes.txt' => 'plain text',
    ]);

    expect(readerUnderTest()->executablesIn($root))->toBe(
        ['Contents/MacOS/Beatrax'],
        'the magic test no longer recognises a Mach-O executable, so every bundle now reads as carrying none',
    );
});

it('finds no executable in a tree that holds none', function (): void {
    $root = aTreeOf([
        'Contents/Info.plist' => '<plist/>',
        'Contents/Resources/notes.txt' => 'plain text',
        'Contents/Resources/nested/data.json' => '{}',
    ]);

    expect(readerUnderTest()->executablesIn($root))->toBe([]);
});

// Not "no executables": a walk of nothing and a walk that found nothing read
// identically, and only one of them means the path was wrong.
it('finds no executable in a directory that is not there', function (): void {
    expect(readerUnderTest()->executablesIn(sys_get_temp_dir().'/beatrax-absent-'.bin2hex(random_bytes(4))))->toBe([]);
});

// No positive control for these two: both shell out to codesign, which exists
// only on macOS, and every CI runner here is Linux. A Darwin-gated control
// would skip in every job, which is the shape .github/test-skip-budget.json
// exists to refuse.
it('reads an empty entitlement set when codesign cannot answer', function (): void {
    // A path no signature covers. On a machine without codesign the tool is
    // missing rather than refusing, and both mean the same thing here.
    expect(readerUnderTest()->entitlementsOf(aTreeOf(['a.txt' => 'x']).'/a.txt'))->toBe([]);
});

it('reports a plain file as unsigned', function (): void {
    expect(readerUnderTest()->isSigned(aTreeOf(['a.txt' => 'x']).'/a.txt'))->toBeFalse();
});

it('answers for exactly the bundles that are installed, on a machine with none too', function (): void {
    $bundles = readerUnderTest()->installedBundles();
    $installed = glob('/Applications/*.app');

    // True on a Linux runner, where the directory does not exist and the
    // answer is an empty map rather than a failure.
    expect(array_keys($bundles))->toBe($installed === false ? [] : $installed);

    if (is_dir('/Applications')) {
        expect($bundles)->not->toBe([], 'A Mac with /Applications and no bundle in it is the walk having stopped, not an empty disk.');
    }

    foreach ($bundles as $what) {
        expect($what['from_the_store'])->toBeBool();
        expect($what['electron'])->toBeBool();
    }
});
