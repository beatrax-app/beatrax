<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Tell PHP which zone the iPhone is in, because nothing else will.
 *
 * `HostTimezone::detect()` asks four sources in order: an environment variable
 * the shell may fill, the `/etc/localtime` symlink, `/etc/timezone`, and the
 * Windows registry. Its own comment claimed "macOS, Linux and iOS all symlink
 * /etc/localtime into the zoneinfo tree". Measured inside the app on an iPhone
 * (iOS 26.5.2), that is not true of iOS:
 *
 *     link_exists              false      <- /etc/localtime is not visible
 *     is_link                  false
 *     file_exists_etc_timezone false
 *     TZ                       false
 *     shell_env                false
 *     date_default             UTC
 *     IntlTimeZone::createDefault()->getID()   CET
 *
 * The sandbox does not show the app `/etc` at all, so every source fails and
 * the floor answers UTC. The phone was on CEST, and the application's log wrote
 * 06:47 while the wall clock said 08:48 — two hours out, in the frame every
 * DATETIME column is stored in.
 *
 * Development builds hide it: both `.env` files pin `APP_TIMEZONE`, so tier one
 * wins and `detect()` never runs. `.env.bundled` deliberately omits it, which
 * makes UTC the answer on every shipped iOS build. It is the same defect the
 * Android shell had until it began supplying the same variable from
 * `getprop persist.sys.timezone`.
 *
 * ICU knows, and is not enough on its own: it answers `CET`, which
 * `DateTimeZone::listIdentifiers()` does not contain, so `HostTimezone::isZone()`
 * rejects it and the floor answers anyway. `TimeZone.current.identifier` answers
 * `Europe/Amsterdam`, which is a real identifier and carries the region rather
 * than a bare offset — the distinction that matters once two devices sync and
 * `created_at` is what tells a primary-key collision from a replay.
 *
 * Set beside `PHPRC`, before the interpreter boots, so the value is present for
 * the first request rather than from the second onwards.
 *
 * The generated tree is rebuilt by `native:install`, so this is applied from
 * composer's hooks rather than hand-edited. It is idempotent, and a missing
 * anchor is a hard failure rather than a silent skip.
 *
 * @link ../.docs/features/mobile/architecture.md
 */

$targets = [
    'app' => [
        'path' => 'ios/NativePHP/NativePHPApp.swift',
        'anchor' => "        setenv(\"PHPRC\", phpIniPath, 1)\n",
        'label' => 'PHPRC',
    ],
    'runtime' => [
        'path' => 'ios/NativePHP/Bridge/PersistentPHPRuntime.swift',
        'anchor' => "        setenv(\"NATIVEPHP_PLATFORM\", \"ios\", 1)\n",
        'label' => 'NATIVEPHP_PLATFORM',
    ],
];

$supplied = <<<'SWIFT'

        // The sandbox shows the app no /etc, so PHP's zone detection finds no
        // /etc/localtime, no /etc/timezone and no TZ, and falls to UTC — two
        // hours out from the phone, in the frame every stored date is written
        // in. Measured on iOS 26.5.2. ICU knows the offset but answers "CET",
        // which is not a zone identifier PHP will accept; this one is.
        setenv("BEATRAX_HOST_TIMEZONE", TimeZone.current.identifier, 1)

SWIFT;

$patched = 0;
$skipped = 0;

foreach ($targets as $what => $target) {
    $file = beatraxScaffoldPath($target['path']) ?? '';

    if (! is_file($file)) {
        // The native scaffold is generated on demand and is absent from a
        // fresh checkout; there is nothing to patch until native:install ran.
        fwrite(STDOUT, sprintf("nativephp_ios_host_timezone: no %s in the scaffold yet — skipping.\n", $what));
        exit(0);
    }

    $source = (string) file_get_contents($file);

    if (str_contains($source, 'BEATRAX_HOST_TIMEZONE')) {
        $skipped++;

        continue;
    }

    if (substr_count($source, $target['anchor']) !== 1) {
        fwrite(STDERR, sprintf(
            "nativephp_ios_host_timezone: %s anchor matched %d times in %s, expected 1.\n",
            $target['label'],
            substr_count($source, $target['anchor']),
            basename($file),
        ));
        exit(1);
    }

    file_put_contents($file, str_replace($target['anchor'], rtrim($target['anchor'], "\n")."\n".$supplied, $source));
    $patched++;
}

if ($patched === 0) {
    fwrite(STDOUT, "nativephp_ios_host_timezone: already patched.\n");
    exit(0);
}

fwrite(STDOUT, sprintf(
    "nativephp_ios_host_timezone: supplied the zone to %d of %d iOS runtimes.\n",
    $patched + $skipped,
    count($targets),
));
