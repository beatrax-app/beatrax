#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Measures what the bundled PHP interpreter can still do under the macOS App
 * Sandbox — the runtime a Mac App Store build has to run in.
 *
 * Why this exists:
 *
 *   The Mac App Store lane was recorded as blocked on a runtime strategy, and
 *   the two questions nobody could answer from documentation were whether a
 *   loopback listener and LAN discovery survive the sandbox. Both are
 *   answerable in about a second by signing the interpreter into a bundle and
 *   running it, which is what this does.
 *
 * How it works:
 *
 *   A sandboxed process needs a bundle identity or the kernel kills it at
 *   launch (SIGTRAP, exit 133) — there is no container to confine it to. So
 *   the interpreter is copied into a minimal .app with an Info.plist, signed
 *   ad-hoc with the sandbox entitlements, and asked about itself. Ad-hoc
 *   signing is deliberate: it needs no keychain and no Apple identity, so the
 *   measurement reproduces on any Mac.
 *
 *   Every probe runs TWICE, sandboxed and not. A failure that reproduces
 *   unsandboxed is not a sandbox finding — `bind 5353` fails either way when
 *   another process on the machine already holds the mDNS port, and reading
 *   that as a sandbox restriction is how this measurement would lie.
 *
 * Usage:
 *   php scripts/measure_sandboxed_interpreter.php [output-directory]
 *
 * Exit codes:
 *   0  measured; the comparison is on stdout
 *   1  the interpreter or codesign is not available on this machine
 */
$projectRoot = dirname(__DIR__);
$interpreter = $projectRoot.'/vendor/nativephp/desktop/resources/build/php/php';

if (! is_file($interpreter)) {
    fwrite(STDERR, "measure_sandboxed_interpreter: no bundled interpreter at {$interpreter}.\n");
    fwrite(STDERR, "Run `composer install` in the repo root first; the desktop package stages it there.\n");

    exit(1);
}

if (PHP_OS_FAMILY !== 'Darwin') {
    fwrite(STDERR, "measure_sandboxed_interpreter: the App Sandbox is a macOS thing; nothing to measure here.\n");

    exit(1);
}

$out = $argv[1] ?? $projectRoot.'/storage/app/sandbox-probe';
$bundle = $out.'/SandboxProbe.app';

foreach ([$bundle.'/Contents/MacOS', $bundle.'/Contents/Resources'] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0o700, true) && ! is_dir($directory)) {
        fwrite(STDERR, "measure_sandboxed_interpreter: could not create {$directory}.\n");

        exit(1);
    }
}

file_put_contents($bundle.'/Contents/Info.plist', <<<'PLIST'
    <?xml version="1.0" encoding="UTF-8"?>
    <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
    <plist version="1.0">
    <dict>
        <key>CFBundleIdentifier</key><string>io.nightworks.beatrax.sandboxprobe</string>
        <key>CFBundleExecutable</key><string>php</string>
        <key>CFBundleName</key><string>SandboxProbe</string>
        <key>CFBundlePackageType</key><string>APPL</string>
        <key>CFBundleShortVersionString</key><string>1.0</string>
        <key>CFBundleVersion</key><string>1</string>
    </dict>
    </plist>
    PLIST);

// Exactly what the store lane's entitlements file carries, minus allow-jit:
// a measurement that grants more than the lane would is not a measurement of
// the lane. Whether PCRE's JIT still works without it is one of the answers.
file_put_contents($out.'/sandbox.entitlements', <<<'PLIST'
    <?xml version="1.0" encoding="UTF-8"?>
    <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
    <plist version="1.0">
    <dict>
        <key>com.apple.security.app-sandbox</key><true/>
        <key>com.apple.security.network.client</key><true/>
        <key>com.apple.security.network.server</key><true/>
    </dict>
    </plist>
    PLIST);

file_put_contents($bundle.'/Contents/Resources/probe.php', beatraxSandboxProbeSource());
copy($interpreter, $bundle.'/Contents/MacOS/php');
chmod($bundle.'/Contents/MacOS/php', 0o755);

// After every file is in place: a signature covers the bundle's contents, and
// adding one afterwards invalidates it.
exec('codesign --force --sign - --entitlements '.escapeshellarg($out.'/sandbox.entitlements').' '.escapeshellarg($bundle).' 2>&1', $signing, $signed);

if ($signed !== 0) {
    fwrite(STDERR, "measure_sandboxed_interpreter: codesign refused:\n  ".implode("\n  ", $signing)."\n");

    exit(1);
}

$probe = $bundle.'/Contents/Resources/probe.php';

$sandboxed = beatraxRunProbe($bundle.'/Contents/MacOS/php', $probe);
$plain = beatraxRunProbe($interpreter, $probe);

printf("%-34s %-24s %s\n", 'probe', 'sandboxed', 'unsandboxed (control)');
printf("%s\n", str_repeat('-', 88));

foreach ($plain as $name => $control) {
    $under = $sandboxed[$name] ?? '(not reached)';
    printf("%-34s %-24s %s\n", $name, $under, $control);
}

// A run that produced nothing is the shape this measurement most needs to
// refuse: the kernel kills a sandboxed process with no container at launch,
// and an empty result table would read as "no problems found".
if ($sandboxed === []) {
    fwrite(STDERR, "\nThe sandboxed run produced no output at all. It was killed at launch rather than restricted.\n");

    exit(1);
}

exit(0);

/** @return array<string, string> */
function beatraxRunProbe(string $php, string $probe): array
{
    $lines = [];
    exec(escapeshellarg($php).' '.escapeshellarg($probe).' 2>&1', $lines);

    $parsed = [];

    foreach ($lines as $line) {
        $parts = preg_split('/\s{2,}/', trim($line), 2);

        if (is_array($parts) && count($parts) === 2) {
            $parsed[$parts[0]] = $parts[1];
        }
    }

    return $parsed;
}

function beatraxSandboxProbeSource(): string
{
    return <<<'PROBE'
        <?php
        $r = [];
        $r['pcre jit ini'] = (string) ini_get('pcre.jit');
        $r['pcre match'] = @preg_match('/^(a+)+b$/', str_repeat('a', 20).'b') === 1 ? 'works' : 'FAILED';

        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $r['loopback listener'] = $server === false ? 'FAILED: '.$errstr : 'bound';
        if ($server !== false) {
            $client = @stream_socket_client('tcp://'.stream_socket_get_name($server, false), $ce, $cm, 2);
            $r['loopback connect'] = $client === false ? 'FAILED: '.$cm : 'connected';
            if ($client) { fclose($client); }
            fclose($server);
        }

        $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $r['udp socket'] = $s === false ? 'FAILED' : 'ok';
        if ($s !== false) {
            $joined = @socket_set_option($s, IPPROTO_IP, MCAST_JOIN_GROUP, ['group' => '224.0.0.251', 'interface' => 0]);
            $r['mdns group join'] = $joined ? 'ok' : 'FAILED';
            $sent = @socket_sendto($s, str_repeat("\x00", 12), 12, 0, '224.0.0.251', 5353);
            $r['mdns send'] = $sent === false ? 'FAILED' : $sent.' bytes';
            @socket_close($s);
        }

        $r['proc_open'] = is_resource($p = @proc_open('/bin/echo hi', [1 => ['pipe', 'w']], $pipes)) ? 'ok' : 'REFUSED';
        if (isset($p) && is_resource($p)) { fclose($pipes[1]); proc_close($p); }

        $home = (string) getenv('HOME');
        $r['home'] = str_contains($home, 'Library/Containers') ? 'container' : 'real home';
        $dir = $home.'/Library/Application Support/beatrax-probe';
        $r['mkdir under home'] = (@mkdir($dir, 0700, true) || is_dir($dir)) ? 'ok' : 'REFUSED';

        try {
            $pdo = new PDO('sqlite:'.$dir.'/probe.sqlite');
            $pdo->exec('CREATE TABLE IF NOT EXISTS t (a INTEGER)');
            $pdo->exec('INSERT INTO t VALUES (1)');
            $r['sqlite write'] = 'ok';
        } catch (Throwable $e) {
            $r['sqlite write'] = 'FAILED';
        }

        $r['read /etc/hosts'] = @file_get_contents('/etc/hosts') === false ? 'REFUSED' : 'allowed';
        $r['write /tmp'] = @file_put_contents('/tmp/beatrax-sandbox-probe', 'x') === false ? 'REFUSED' : 'allowed';
        @unlink('/tmp/beatrax-sandbox-probe');

        foreach ($r as $k => $v) { printf("%-30s %s\n", $k, $v); }
        PROBE;
}
