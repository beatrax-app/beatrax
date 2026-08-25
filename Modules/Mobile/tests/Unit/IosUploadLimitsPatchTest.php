<?php

declare(strict_types=1);

// The iOS shell writes its own php.ini and wrote only the two CA paths, so
// post_max_size stayed at the stock 8M. Uploads on that platform are base64 in
// a JSON body, so 8M of body is 6.29 MB of file, and an 8.05 MB encrypted
// backup -- a 3,306-transaction ledger -- was dropped with nothing in any log.

function iosScaffold(string $phpIniBody): string
{
    $root = sys_get_temp_dir().'/beatrax-ios-ini-'.bin2hex(random_bytes(6));
    mkdir($root.'/nativephp/ios/NativePHP/Bridge', 0700, true);

    // Concatenated rather than heredoc: the anchor is indentation-exact, and
    // an indented closing marker would strip the very spaces being matched.
    $swift = "    private func createPhpIni(caPath: String) -> String {\n"
        ."        let supportDir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!\n\n"
        .$phpIniBody."\n\n"
        ."        try? phpIni.write(to: iniPath, atomically: true, encoding: .utf8)\n"
        ."    }\n";

    file_put_contents($root.'/nativephp/ios/NativePHP/NativePHPApp.swift', $swift);
    file_put_contents($root.'/nativephp/ios/NativePHP/Bridge/PersistentPHPRuntime.swift', $swift);

    return $root;
}

function iosStockPhpIni(): string
{
    return <<<'SWIFT'
        let phpIni = """
        curl.cainfo="\(caPath)"
        openssl.cafile="\(caPath)"
        """
SWIFT;
}

function runIosUploadLimits(string $root): array
{
    $script = base_path('scripts/nativephp_ios_upload_limits.php');
    $process = proc_open(
        ['php', $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['BEATRAX_NATIVE_ROOT' => $root, 'PATH' => getenv('PATH')],
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['status' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

function iniBytes(string $value): int
{
    $unit = strtoupper(substr($value, -1));
    $number = (int) substr($value, 0, -1);

    return match ($unit) {
        'G' => $number * 1024 * 1024 * 1024,
        'M' => $number * 1024 * 1024,
        'K' => $number * 1024,
        default => (int) $value,
    };
}

it('gives both php.ini writers an upload ceiling', function (): void {
    $root = iosScaffold(iosStockPhpIni());

    $result = runIosUploadLimits($root);
    expect($result['status'])->toBe(0);

    foreach (['NativePHPApp.swift', 'Bridge/PersistentPHPRuntime.swift'] as $file) {
        $patched = (string) file_get_contents($root.'/nativephp/ios/NativePHP/'.$file);

        expect($patched)->toContain('upload_max_filesize=');
        expect($patched)->toContain('post_max_size=');

        // The CA paths are why the file exists at all, and `\(caPath)` is Swift
        // interpolation -- a patch that flattened either would ship a runtime
        // with no trust store.
        expect($patched)->toContain('curl.cainfo="\(caPath)"');
        expect($patched)->toContain('openssl.cafile="\(caPath)"');
    }
});

// The load-bearing one. post_max_size bounds the base64 body, not the file, so
// a post_max_size merely EQUAL to upload_max_filesize leaves the stated file
// limit unreachable by a quarter and the ceiling is decorative.
it('sets post_max_size high enough to carry an upload_max_filesize file once base64 has inflated it', function (): void {
    $root = iosScaffold(iosStockPhpIni());
    runIosUploadLimits($root);

    $patched = (string) file_get_contents($root.'/nativephp/ios/NativePHP/NativePHPApp.swift');

    preg_match('/upload_max_filesize=(\S+)/', $patched, $upload);
    preg_match('/post_max_size=(\S+)/', $patched, $post);
    expect($upload)->toHaveCount(2);
    expect($post)->toHaveCount(2);

    $fileBytes = iniBytes($upload[1]);
    $bodyBytes = iniBytes($post[1]);

    expect($bodyBytes)->toBeGreaterThanOrEqual((int) ceil($fileBytes * 4 / 3));

    // And the whole point: an ordinary encrypted backup has to fit. 8,046,695
    // bytes is a real one, off an iPhone, for a 3,306-transaction ledger.
    expect($fileBytes)->toBeGreaterThan(8_046_695);
});

it('is idempotent, because native:install regenerates the tree under it', function (): void {
    $root = iosScaffold(iosStockPhpIni());

    runIosUploadLimits($root);
    $once = (string) file_get_contents($root.'/nativephp/ios/NativePHP/NativePHPApp.swift');

    $again = runIosUploadLimits($root);
    $twice = (string) file_get_contents($root.'/nativephp/ios/NativePHP/NativePHPApp.swift');

    expect($again['status'])->toBe(0);
    expect($twice)->toBe($once);
    expect($again['stdout'])->toContain('already patched');
});

// A silent skip would ship the stock ceiling, which is the defect. The build
// has to stop instead.
it('fails loudly when the generated shell writes its php.ini some other way', function (): void {
    $root = iosScaffold('        let phpIni = "curl.cainfo=/somewhere/else"');

    $result = runIosUploadLimits($root);

    expect($result['status'])->toBe(1);
    expect($result['stderr'])->toContain('anchor not found');
});

it('skips a checkout that has no iOS scaffold yet', function (): void {
    $root = sys_get_temp_dir().'/beatrax-ios-ini-empty-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    $result = runIosUploadLimits($root);

    expect($result['status'])->toBe(0);
    expect($result['stdout'])->toContain('skipping');
});
