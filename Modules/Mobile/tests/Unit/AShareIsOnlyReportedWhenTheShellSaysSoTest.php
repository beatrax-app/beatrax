<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Native\NativeShareSheet;

// Share::file() is typed void. It calls nativephp_call() and drops the answer,
// so every outcome the shell can report — a cancel, a provider that refused, a
// file it could not read — reached the reader as "Saved as ...".
function shareSheetSeamSource(): string
{
    return (string) file_get_contents(
        base_path('Modules/Mobile/Internal/Native/NativeShareSheet.php')
    );
}

function shareAnswerReadsAsSuccess(string $answer): bool
{
    return (bool) (new ReflectionMethod(NativeShareSheet::class, 'answersSuccess'))
        ->invoke(null, $answer);
}

// Comments name the facade on purpose — the seam's own docblock explains why
// it is not called — so the scan reads code tokens and drops every comment
// rather than matching the phrase wherever it appears.
function shareSeamCodeWithoutComments(string $path): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

/** @return list<string> every module PHP file outside its tests */
function moduleSourceFilesNamingTheShareFacade(): array
{
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules'), FilesystemIterator::SKIP_DOTS)
    );

    $scanned = 0;
    $callers = [];

    /** @var SplFileInfo $entry */
    foreach ($walk as $entry) {
        $path = (string) $entry;

        if (! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
            continue;
        }

        $scanned++;

        if (str_contains(shareSeamCodeWithoutComments($path), 'Share::file(')) {
            $callers[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($scanned)->toBeGreaterThan(500, 'the walk read almost nothing, so an empty result proves nothing');

    return $callers;
}

it('reads the two shapes the shell spells success with', function (): void {
    expect(shareAnswerReadsAsSuccess('{"status":"success"}'))->toBeTrue()
        ->and(shareAnswerReadsAsSuccess('{"success":true}'))->toBeTrue();
});

it('reads every other answer as a share that did not happen', function (): void {
    $refused = [
        '{"status":"error","message":"Function \'Share.File\' not found"}',
        '{"status":"cancelled"}',
        '{"success":false}',
        '{"success":"true"}',
        '{"status":"SUCCESS"}',
        '{}',
        '[]',
        'not json at all',
        '',
    ];

    foreach ($refused as $answer) {
        expect(shareAnswerReadsAsSuccess($answer))->toBeFalse(
            'this answer was read as a completed share: '.$answer
        );
    }
});

// A share reported off a device would be a claim about a sheet that cannot
// have opened, so the absent bridge is the answer here rather than a
// precondition the caller is trusted to have checked first.
it('reports no share when the shell has no bridge to ask', function (): void {
    expect(function_exists('nativephp_call'))->toBeFalse(
        'this suite has a native bridge, so the case below proves nothing'
    );

    expect((new NativeShareSheet)->file('t', 'm', '/tmp/nothing.txt'))->toBeFalse();
});

// The facade is the defect. Naming it anywhere in the app puts back the void
// call whose answer nobody can read, so the seam is pinned to the raw bridge.
it('never hands a file to the facade that discards the answer', function (): void {
    $callers = moduleSourceFilesNamingTheShareFacade();

    expect($callers)->toBe([], implode("\n", [
        'Share::file() is typed void: it drops what the shell answered, so a caller',
        'cannot tell a share that happened from one that never did. Call the bridge',
        'directly and read the answer, the way NativeShareSheet::file() does. Files:',
        ...$callers,
    ]));
});

it('passes the bridge answer through the reader instead of assuming it', function (): void {
    $source = shareSheetSeamSource();

    expect(str_contains($source, 'self::answersSuccess(nativephp_call('))->toBeTrue(
        'NativeShareSheet::file() no longer reads what the shell answered'
    );

    expect(str_contains($source, 'return true;'))->toBeFalse(
        'NativeShareSheet::file() is reporting a share it did not verify again'
    );
});
