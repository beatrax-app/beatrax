#!/usr/bin/env php
<?php

declare(strict_types=1);

use Modules\Core\Internal\Build\BuiltFrontEnd;

/**
 * Refuses a shipping path carrying a front end older than its own sources.
 *
 * The rule is BuiltFrontEnd, and the seam that normally asks it is a
 * CommandStarting listener: native:build, native:package, native:run and
 * mobile:package-android all stop there. materialize.sh is the one shipping
 * path that is not an artisan command — it rsyncs public/ into the Bifrost
 * build repo, and the job that runs it installs no Composer dependencies at
 * all, so there is no application to boot and no autoloader to reach it with.
 *
 * Requiring the two classes by hand is what keeps ONE definition of "newer
 * than". A second comparison written in shell would be a second answer to the
 * same question, free to drift from the one a device is actually refused on.
 *
 * Usage: refuse_a_stale_front_end.php [public-directory] [caller-name]
 *
 * @link ../.docs/conventions/invariants-from-shipped-failures.md#a-built-front-end-older-than-the-sources-it-was-compiled-from
 */
require __DIR__.'/../Modules/Core/Internal/Build/StaleFrontEnd.php';
require __DIR__.'/../Modules/Core/Internal/Build/BuiltFrontEnd.php';

$publicDirectory = $argv[1] ?? __DIR__.'/../public';
$caller = $argv[2] ?? basename(__FILE__);

$stale = BuiltFrontEnd::beside($publicDirectory)->staleness();

if ($stale === null) {
    echo "front-end check: public/build is newer than every source it is compiled from.\n";

    exit(0);
}

fwrite(STDERR, $stale->sentence($caller)."\n");

exit(1);
