<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Boot\ShippedBundleContents;

// An exclusion list is a claim about a build, and the claim has been wrong: a
// shipped iPhone build carried the build machine's own encrypted sync
// identities, every one of them gitignored. What keeps a secret out of an
// artifact is reading the artifact.

/** @param  array<string, string>  $entries  path inside the archive => contents */
function bundleArchive(array $entries, string $extension = 'apk'): string
{
    $path = sys_get_temp_dir().'/bundle-fixture-'.bin2hex(random_bytes(6)).'.'.$extension;

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();

    return $path;
}

function bundleRefusals(string $path): array
{
    return app(ShippedBundleContents::class)->refusals($path);
}

afterEach(function (): void {
    foreach ((array) glob(sys_get_temp_dir().'/bundle-fixture-*') as $leftover) {
        if (is_string($leftover) && is_file($leftover)) {
            @unlink($leftover);
        }
    }
});

it('accepts an artifact carrying none of the three', function (): void {
    $path = bundleArchive([
        'assets/app/.env' => "APP_ENV=production\nAPP_DEBUG=false\n",
        'assets/app/routes/web.php' => '<?php // routes',
        'classes.dex' => "\0\0binary",
    ]);

    expect(bundleRefusals($path))->toBe([]);
});

it('refuses key material by whatever name it travels under', function (string $name): void {
    $path = bundleArchive(['assets/'.$name => 'not really a key']);

    expect(bundleRefusals($path))->toHaveCount(1)
        ->and(bundleRefusals($path)[0])->toContain('key material');
})->with([
    'a keystore' => 'app-release-key.jks',
    'a PKCS#12 bundle' => 'dist.p12',
    'an Apple provisioning profile' => 'beatrax.mobileprovision',
    'a private key' => 'signing.pem',
]);

it('refuses a database, because the phone migrates and ships none', function (): void {
    $path = bundleArchive(['assets/app/database/database.sqlite' => 'SQLite format 3']);

    expect(bundleRefusals($path))->toHaveCount(1)
        ->and(bundleRefusals($path)[0])->toContain('a database');
});

it('refuses a secret that still carries a value', function (): void {
    $path = bundleArchive([
        'assets/app/.env' => "APP_ENV=production\nANDROID_KEYSTORE_PASSWORD=hunter2\n",
    ]);

    $refusals = bundleRefusals($path);

    expect($refusals)->toHaveCount(1);
    expect($refusals[0])->toContain('ANDROID_KEYSTORE_PASSWORD');
});

it('accepts a key that was stripped rather than merely mentioned', function (): void {
    // What cleanup_env_keys leaves behind, and what a comment about the strip
    // rule looks like. Neither carries anything, and refusing them would make
    // the check unusable on the artifact it is for.
    $path = bundleArchive([
        'assets/app/.env' => "APP_ENV=production\nANDROID_KEYSTORE_PASSWORD=\n# ANDROID_KEY_ALIAS=stripped at build time\n",
    ]);

    expect(bundleRefusals($path))->toBe([]);
});

it('reads the archive the artifact carries, not only the artifact', function (): void {
    // The PHP application travels inside the build as its own zip. A scan that
    // stopped at the outer entries would read the wrapper and call it clean.
    $inner = bundleArchive(['app/.env' => "APP_STORE_API_KEY=abc123\n"], 'zip');

    $path = bundleArchive([
        'classes.dex' => "\0\0binary",
        'assets/php_app.zip' => (string) file_get_contents($inner),
    ]);

    $refusals = bundleRefusals($path);

    expect($refusals)->not->toBe([], 'a secret one archive deep was not reached');
    expect(implode("\n", $refusals))->toContain('APP_STORE_API_KEY');
});

it('fails the command when the artifact is not there at all', function (): void {
    // A renamed or missing output must not read as an artifact that passed.
    $this->artisan('mobile:inspect-bundle', ['path' => sys_get_temp_dir().'/no-such-bundle.apk'])
        ->assertExitCode(1);
});

it('passes the command on a clean artifact', function (): void {
    $path = bundleArchive(['assets/app/.env' => "APP_ENV=production\n"]);

    $this->artisan('mobile:inspect-bundle', ['path' => $path])->assertExitCode(0);
});

// Every other way out of refusals() reports what it read. An empty list has to
// mean "read it, found none" — and each of these used to make it also mean
// "read nothing at all", which is the answer that ships the artifact.
it('refuses an archive that unpacked to no files at all', function (): void {
    $path = sys_get_temp_dir().'/bundle-fixture-'.bin2hex(random_bytes(6)).'.apk';

    // ZipArchive will not write an archive with no entries, so the 22 bytes of
    // a bare end-of-central-directory record stand in for one.
    file_put_contents($path, "PK\x05\x06".str_repeat("\0", 18));

    expect(implode("\n", bundleRefusals($path)))->toContain('unpacked to no files');
});

it('refuses an archive it could not extract, rather than walking what did land', function (): void {
    $path = sys_get_temp_dir().'/bundle-fixture-'.bin2hex(random_bytes(6)).'.apk';

    // One entry is a file and the next needs that same name to be a directory,
    // so extraction stops part-way with some of the artifact on disk.
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('x', 'a file');
    $zip->addFromString('x/y', 'and a directory of the same name');
    $zip->close();

    expect(implode("\n", bundleRefusals($path)))->toContain('would not extract');
});

it('refuses a nested archive it could not open, instead of reading its bytes', function (): void {
    $path = bundleArchive([
        'classes.dex' => "\0\0binary",
        'assets/php_app.zip' => 'ANDROID_KEYSTORE_PASSWORD=hunter2',
    ]);

    // The wrapper reads as text, so the secret inside it is found either way —
    // what must not happen is the archive being passed over in silence.
    expect(implode("\n", bundleRefusals($path)))->toContain('would not open');
});

// An artifact that names an entry after where the nested unpack has to land
// blocks the move, and the nested tree then sits outside every later walk. The
// wrapper's own bytes are all that would be read — which is the shape the
// nested pass exists to prevent, arrived at from the other side.
it('refuses when a nested archive cannot be moved into the tree it walks', function (): void {
    $inner = bundleArchive(['app/.env' => "APP_STORE_API_KEY=abc123\n"], 'zip');

    $path = bundleArchive([
        'classes.dex' => "\0\0binary",
        'assets/php_app.zip' => (string) file_get_contents($inner),
        'assets/php_app.zip.unpacked/occupied' => 'this name is taken',
    ]);

    expect(implode("\n", bundleRefusals($path)))->toContain('out of reach');
});
