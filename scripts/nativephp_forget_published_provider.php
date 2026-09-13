<?php

declare(strict_types=1);

/*
 * Removes the NativeAppServiceProvider that `native:install` republishes into
 * app/Providers/ on every `composer update`, and prunes the directories it
 * arrives in.
 *
 * The publish is not optional and not ours to switch off: the Electron install
 * command calls `vendor:publish --tag=nativephp-provider` unconditionally, and
 * spatie/laravel-package-tools hard-codes the destination as
 * base_path("app/Providers/{$providerName}.php"). So the file reappears
 * whatever this repository does, and without this step the app/ directory
 * reappears with it.
 *
 * Nothing reads the published copy. config/nativephp.php names
 * Modules\Desktop\Internal\NativeAppServiceProvider as the provider, the mobile
 * root invokes its own from bootstrap/app.php, and the published stub is listed
 * in no providers file. It is published, ignored, and now deleted.
 *
 * `--force` is deliberately not passed to vendor:publish by that command, so a
 * file that already exists is left alone. config/nativephp.php, which carries
 * this repository's own exclusion lists, is therefore never overwritten.
 */

$root = dirname(__DIR__);
$published = $root.'/app/Providers/NativeAppServiceProvider.php';

if (is_file($published) && ! @unlink($published)) {
    fwrite(STDERR, sprintf("nativephp_forget_published_provider: could not delete %s\n", $published));

    exit(1);
}

// Only ever removes a directory that is empty, so a directory someone has since
// given a reason to exist is left where it is.
foreach ([$root.'/app/Providers', $root.'/app'] as $directory) {
    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

if (is_dir($root.'/app')) {
    fwrite(STDERR, "nativephp_forget_published_provider: app/ still holds files:\n");
    foreach ((array) scandir($root.'/app') as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            fwrite(STDERR, '  '.$entry."\n");
        }
    }

    exit(1);
}
