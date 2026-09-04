<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The client refuses a file the server would refuse anyway, before reading it,
// because the encode is what kills the tab rather than the request. Two numbers
// that must agree: if the client's is the larger, a pick between them allocates
// ~3.7x the file in the content process and the WebView dies with no crash
// report at all.
it('gives the browser the same upload ceiling the iOS runtime is given', function (): void {
    $js = (string) file_get_contents(base_path('resources/js/mobile-upload.js'));
    $patch = (string) file_get_contents(base_path('scripts/nativephp_ios_upload_limits.php'));

    $client = PatternScan::first('/const MAX_UPLOAD_BYTES = (\d+) \* 1024 \* 1024;/', $js);
    $server = PatternScan::first("/BEATRAX_IOS_UPLOAD_MAX_FILESIZE = '(\d+)M';/", $patch);

    expect($client)->not->toBe([]);
    expect($server)->not->toBe([]);

    expect((int) $client[1])->toBe((int) $server[1]);
});

// post_max_size bounds the base64 body, not the file. Equal to the file limit
// it would leave the stated ceiling unreachable by a quarter.
it('keeps the body ceiling above the file ceiling by the cost of base64', function (): void {
    $patch = (string) file_get_contents(base_path('scripts/nativephp_ios_upload_limits.php'));

    $file = PatternScan::first("/BEATRAX_IOS_UPLOAD_MAX_FILESIZE = '(\d+)M';/", $patch);
    $body = PatternScan::first("/BEATRAX_IOS_POST_MAX_SIZE = '(\d+)M';/", $patch);

    expect($file)->not->toBe([]);
    expect($body)->not->toBe([]);

    expect((int) $body[1])->toBeGreaterThanOrEqual((int) ceil((int) $file[1] * 4 / 3));
});
