<?php

declare(strict_types=1);

use Modules\Core\Public\Support\BladePhpSource;
use Modules\Mobile\Internal\Native\BridgeAnswer;
use Modules\Mobile\Internal\Native\NativeShareSheet;
use Native\Mobile\Testing\FakeBridge;

// Share::file() is typed void. It calls nativephp_call() and drops the answer,
// so every outcome the shell can report — a cancel, a provider that refused, a
// file it could not read — reached the reader as a share that happened.
function shareSeamSource(): string
{
    return (string) file_get_contents(
        base_path('Modules/Mobile/Internal/Native/NativeShareSheet.php')
    );
}

function shareAnswerReadsAsSuccess(string $answer): bool
{
    return BridgeAnswer::saysItSucceeded($answer);
}

/** @return list<string> module sources naming the void facade, tests aside */
function moduleSourcesNamingTheShareFacade(): array
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

        if (str_contains(shareScanCodeOf($path), 'Share::file(')) {
            $callers[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($scanned)->toBeGreaterThan(500, 'the walk read almost nothing, so an empty result proves nothing');

    return $callers;
}

// Comments name the facade on purpose — the seam's own docblock explains why
// it is not called — so the scan drops them and reads code only.

// A `.php` walk holds the Blade templates too, and token_get_all reads one as a
// single T_INLINE_HTML: BladePhpSource hands back the islands instead, so an
// `@php` block calling the facade is read rather than waved through.
function shareScanCodeOf(string $path): string
{
    $source = BladePhpSource::forPath($path, (string) file_get_contents($path));
    $code = '';

    foreach (token_get_all($source) as $token) {
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

it('reads the two shapes the shell spells success with', function (): void {
    expect(shareAnswerReadsAsSuccess('{"status":"success"}'))->toBeTrue()
        ->and(shareAnswerReadsAsSuccess('{"success":true}'))->toBeTrue();
});

it('reads every other answer as a share that did not happen', function (): void {
    $refused = [
        '{"status":"error","code":"INVALID_PARAMETERS","message":"id is required"}',
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

// The whole fix, driven over the bridge the device answers on. The Android
// handler this pins against returns mapOf("success" to false) four ways: no
// file at the path, a staging copy the cache refused, no FileProvider path
// covering it, and nothing on the device accepting the intent.
it('answers the caller with what the bridge said', function (): void {
    if (! class_exists(FakeBridge::class)) {
        expect((new NativeShareSheet)->file('t', 'm', '/tmp/beatrax-no-such-file'))->toBeFalse(
            'this Composer root has no mobile package, so there is no bridge to ask and the seam must refuse'
        );

        return;
    }

    $bridge = FakeBridge::enable();

    try {
        $bridge->respondTo('Share.File', '{"success":false}');
        expect((new NativeShareSheet)->file('t', 'm', '/tmp/x'))->toBeFalse(
            'the shell said the share did not happen and the seam reported it as one that did'
        );

        $bridge->respondTo('Share.File', '{"status":"error","code":"NO_ACTIVITY","message":"Nothing accepted the share intent"}');
        expect((new NativeShareSheet)->file('t', 'm', '/tmp/x'))->toBeFalse();

        $bridge->respondTo('Share.File', '{"success":true}');
        expect((new NativeShareSheet)->file('t', 'm', '/tmp/x'))->toBeTrue(
            'a share the shell confirmed was reported as a failure'
        );

        $bridge->assertCalled('Share.File');
    } finally {
        FakeBridge::disable();
    }
});

// The facade is the defect. Naming it anywhere in the app puts back the void
// call whose answer nobody can read, so the seam is pinned to the raw bridge.
it('never hands a file to the facade that discards the answer', function (): void {
    $callers = moduleSourcesNamingTheShareFacade();

    expect($callers)->toBe([], implode("\n", [
        'Share::file() is typed void: it drops what the shell answered, so a caller',
        'cannot tell a share that happened from one that never did. Call the bridge',
        'directly and read the answer, the way NativeShareSheet::file() does. Files:',
        ...$callers,
    ]));
});

it('passes the bridge answer through the reader instead of assuming it', function (): void {
    $source = shareSeamSource();

    expect(str_contains($source, 'BridgeAnswer::saysItSucceeded(nativephp_call('))->toBeTrue(
        'NativeShareSheet::file() no longer reads what the shell answered'
    );

    expect(str_contains($source, 'return true;'))->toBeFalse(
        'NativeShareSheet::file() is reporting a share it did not verify again'
    );
});
