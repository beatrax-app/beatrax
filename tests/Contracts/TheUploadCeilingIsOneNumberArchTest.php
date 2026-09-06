<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The two device runtimes that carry a ceiling of their own. One client serves
// both, so a rule reading only iOS lets the Android pair drift under the same
// browser number and reports the ceiling as "one number" while holding two.
const UPLOAD_CEILING_PATCHES = [
    'iOS' => ['path' => 'scripts/nativephp_ios_upload_limits.php', 'prefix' => 'BEATRAX_IOS'],
    'Android' => ['path' => 'scripts/nativephp_android_upload_limits.php', 'prefix' => 'BEATRAX_ANDROID'],
];

/** @return array{file: int, body: int} the megabyte ceilings one runtime's patch declares */
function uploadCeilingMegabytes(string $prefix, string $patch): array
{
    $file = PatternScan::first('/'.$prefix.'_UPLOAD_MAX_FILESIZE = \'(\d+)M\';/', $patch);
    $body = PatternScan::first('/'.$prefix.'_POST_MAX_SIZE = \'(\d+)M\';/', $patch);

    expect($file)->not->toBe([], $prefix.'_UPLOAD_MAX_FILESIZE was not found — the scan is wrong, not the patch.');
    expect($body)->not->toBe([], $prefix.'_POST_MAX_SIZE was not found — the scan is wrong, not the patch.');

    return ['file' => (int) $file[1], 'body' => (int) $body[1]];
}

// The client refuses a file the server would refuse anyway, before reading it,
// because the encode is what kills the tab rather than the request. Two numbers
// that must agree: if the client's is the larger, a pick between them allocates
// ~3.7x the file in the content process and the WebView dies with no crash
// report at all.
it('gives the browser the same upload ceiling every device runtime is given', function (): void {
    $js = (string) file_get_contents(base_path('resources/js/mobile-upload.js'));

    $client = PatternScan::first('/const MAX_UPLOAD_BYTES = (\d+) \* 1024 \* 1024;/', $js);

    expect($client)->not->toBe([], 'MAX_UPLOAD_BYTES was not found in resources/js/mobile-upload.js — the scan is wrong, not the client.');

    $disagreeing = [];

    foreach (UPLOAD_CEILING_PATCHES as $runtime => $patch) {
        $ceilings = uploadCeilingMegabytes($patch['prefix'], (string) file_get_contents(base_path($patch['path'])));

        if ($ceilings['file'] !== (int) $client[1]) {
            $disagreeing[] = $runtime.' allows '.$ceilings['file'].'M where the client allows '.$client[1].'M';
        }
    }

    expect($disagreeing)->toBe([], implode("\n  ", [
        'One client serves both device runtimes, so one ceiling has to answer for all three sites.',
        'A client ceiling above a runtime\'s allocates ~3.7x the file in the content process for a',
        'pick the server was always going to refuse, and the WebView dies with no crash report:',
        ...$disagreeing,
    ]));
});

// post_max_size bounds the base64 body, not the file. Equal to the file limit
// it would leave the stated ceiling unreachable by a quarter.
it('keeps the body ceiling above the file ceiling by the cost of base64', function (): void {
    $tooTight = [];

    foreach (UPLOAD_CEILING_PATCHES as $runtime => $patch) {
        $ceilings = uploadCeilingMegabytes($patch['prefix'], (string) file_get_contents(base_path($patch['path'])));
        $needed = (int) ceil($ceilings['file'] * 4 / 3);

        if ($ceilings['body'] < $needed) {
            $tooTight[] = $runtime.' bounds the body at '.$ceilings['body'].'M, under the '.$needed.'M a '.$ceilings['file'].'M file costs once base64 encoded';
        }
    }

    expect($tooTight)->toBe([], implode("\n  ", [
        'post_max_size bounds the encoded body, not the file. Set equal to the file ceiling it',
        'leaves the last quarter of the stated ceiling unreachable, and the refusal arrives as a',
        'body PHP discarded rather than as the size error the client would have shown:',
        ...$tooTight,
    ]));
});

it('reads both patches and the client, so a silent scan cannot pass this file', function (): void {
    expect(UPLOAD_CEILING_PATCHES)->toHaveCount(2, 'a device runtime left the map this guard compares against.');

    foreach (UPLOAD_CEILING_PATCHES as $runtime => $patch) {
        expect(is_file(base_path($patch['path'])))->toBeTrue(
            'the '.$runtime.' upload-limit patch is not at '.$patch['path'].', so its ceilings are compared with nothing.',
        );
    }

    expect(is_file(base_path('resources/js/mobile-upload.js')))->toBeTrue(
        'the client that enforces the ceiling in the browser was not found, so the comparison above has one side.',
    );
});
