<?php

declare(strict_types=1);

use Modules\Desktop\Internal\Boot\MacBundleReader;

// The reader is the half that talks to the machine, and most of what it does
// answers the same on any machine: a directory walk, a file-magic question,
// and — where codesign is absent — the refusal that means "nothing was read".

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

it('reads an empty entitlement set when codesign cannot answer', function (): void {
    // A path no signature covers. On a machine without codesign the tool is
    // missing rather than refusing, and both mean the same thing here.
    expect(readerUnderTest()->entitlementsOf(aTreeOf(['a.txt' => 'x']).'/a.txt'))->toBe([]);
});

it('reports a plain file as unsigned', function (): void {
    expect(readerUnderTest()->isSigned(aTreeOf(['a.txt' => 'x']).'/a.txt'))->toBeFalse();
});

it('answers about installed bundles without assuming any are installed', function (): void {
    $bundles = readerUnderTest()->installedBundles();

    foreach ($bundles as $path => $what) {
        expect($path)->toStartWith('/Applications/');
        expect($what)->toHaveKeys(['from_the_store', 'electron']);
        expect($what['from_the_store'])->toBeBool();
        expect($what['electron'])->toBeBool();
    }

    expect(array_is_list($bundles))->toBeFalse();
});
