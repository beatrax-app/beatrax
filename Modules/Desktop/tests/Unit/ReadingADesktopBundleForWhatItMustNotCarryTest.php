<?php

declare(strict_types=1);

use Modules\Desktop\Commands\InspectDesktopBundleCommand;
use Modules\Desktop\Internal\Boot\ShippedDesktopContents;

// An exclusion list is a claim about a build, and the claim was wrong: the
// desktop tree carried amphp/http-server's TLS test key and firebase/php-jwt's
// README key because `pruneVendorDirectory()` drops `vendor/bin` and the PHP
// binary package and nothing else. What keeps a key out of an artifact is
// reading the artifact.

/** @param array<string, string> $entries relative path => contents */
function desktopTreeOf(array $entries): string
{
    $root = sys_get_temp_dir().'/desktop-tree-'.bin2hex(random_bytes(6));

    foreach ($entries as $name => $contents) {
        $path = $root.'/'.$name;
        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, $contents);
    }

    return $root;
}

// Base64 that really decodes, wrapped the way OpenSSL writes it. The detector's
// question is whether the bytes between the markers are a key rather than a
// file talking about one, so the fixture has to answer it honestly.
function desktopPemBlock(string $label = 'PRIVATE KEY'): string
{
    return sprintf(
        "-----BEGIN %s-----\n%s-----END %s-----\n",
        $label,
        chunk_split(base64_encode(str_repeat('k', 320)), 64, "\n"),
        $label,
    );
}

/** @return list<string> */
function desktopTreeRefusals(string $root): array
{
    return app(ShippedDesktopContents::class)->refusals($root);
}

afterEach(function (): void {
    foreach ((array) glob(sys_get_temp_dir().'/desktop-tree-*') as $leftover) {
        if (is_string($leftover) && is_dir($leftover)) {
            exec('rm -rf '.escapeshellarg($leftover));
        }
    }
});

it('accepts a tree carrying none of the four', function (): void {
    $root = desktopTreeOf([
        'Contents/Resources/build/app/.env' => "APP_ENV=production\nAPP_DEBUG=false\n",
        'Contents/Resources/build/app/routes/web.php' => '<?php // routes',
        'Contents/MacOS/beatrax' => "\x00\x00binary",
    ]);

    expect(desktopTreeRefusals($root))->toBe([]);
});

it('refuses a private key by its bytes, under whatever name it travels', function (string $name): void {
    $root = desktopTreeOf(['Contents/Resources/build/app/'.$name => desktopPemBlock()]);

    expect(desktopTreeRefusals($root))->toHaveCount(1)
        ->and(desktopTreeRefusals($root)[0])->toContain('key material');
})->with([
    'a named key file' => 'sync/identity.pem',
    'an extension that claims nothing' => 'storage/blob.dat',
    'a package README' => 'vendor/firebase/php-jwt/README.md',
]);

// The header is a substring of the footer, and a key inlined in JSON begins no
// line, so neither a line anchor nor a header match can decide this.
it('refuses a key inlined in JSON, where no marker starts a line', function (): void {
    $root = desktopTreeOf([
        'app/service-account.json' => '{"type":"service_account","private_key":"'
            .str_replace("\n", '\\n', desktopPemBlock()).'"}',
    ]);

    expect(desktopTreeRefusals($root))->toHaveCount(1)
        ->and(desktopTreeRefusals($root)[0])->toContain('key material');
});

// The bundle ships 151 public root certificates so the PHP runtime can verify
// TLS. Judging a `.pem` by its name refuses that file on every build, which is
// a check nobody can leave switched on.
it('accepts a certificate bundle, which is a public list and not a secret', function (): void {
    $root = desktopTreeOf([
        'Contents/Resources/build/cacert.pem' => desktopPemBlock('CERTIFICATE')
            .desktopPemBlock('CERTIFICATE'),
    ]);

    expect(desktopTreeRefusals($root))->toBe([]);
});

// A header-substring match refused two PHP source files that merely name the
// marker. Counting BEGIN/END pairs alone would still refuse them, because a
// formatter declares both constants.
it('accepts source that names the markers with nothing between them', function (): void {
    $root = desktopTreeOf([
        'vendor/acme/pem/src/Writer.php' => <<<'PHP'
            <?php
            const OPENS = '-----BEGIN PRIVATE KEY-----';
            const CLOSES = '-----END PRIVATE KEY-----';
            PHP,
    ]);

    expect(desktopTreeRefusals($root))->toBe([]);
});

it('refuses a container whose whole purpose is to hold a key', function (string $name): void {
    $root = desktopTreeOf(['Contents/Resources/'.$name => 'not really a key']);

    expect(desktopTreeRefusals($root))->toHaveCount(1)
        ->and(desktopTreeRefusals($root)[0])->toContain('key material');
})->with([
    'a keystore' => 'release.jks',
    'a PKCS#12 bundle' => 'dist.p12',
    'an Apple provisioning profile' => 'beatrax.mobileprovision',
]);

it('refuses a database, because the desktop writes its own outside the bundle', function (): void {
    $root = desktopTreeOf(['Contents/Resources/build/app/database/database.sqlite' => 'SQLite format 3']);

    expect(desktopTreeRefusals($root))->toHaveCount(1)
        ->and(desktopTreeRefusals($root)[0])->toContain('a database');
});

// Every desktop build carries one, created by the `->booting()` hook so the
// SQLite connector has a file to open. Refusing it by extension would refuse
// every build, which is a check nobody can leave switched on.
it('accepts the empty database file the bootstrap hook creates', function (): void {
    $root = desktopTreeOf([
        'Contents/Resources/build/app/database/database.sqlite' => '',
        'Contents/Resources/build/app/artisan' => '<?php // artisan',
    ]);

    expect(desktopTreeRefusals($root))->toBe([]);
});

it('refuses a build credential that still carries a value', function (): void {
    $root = desktopTreeOf([
        'Contents/Resources/build/app/.env' => "APP_ENV=production\nCSC_KEY_PASSWORD=hunter2\n",
    ]);

    expect(desktopTreeRefusals($root))->toHaveCount(1)
        ->and(desktopTreeRefusals($root)[0])->toContain('CSC_KEY_PASSWORD');
});

it('accepts a credential that was stripped rather than merely mentioned', function (): void {
    $root = desktopTreeOf([
        'Contents/Resources/build/app/.env' => "# GITHUB_TOKEN=old\nGITHUB_TOKEN=\nAWS_SECRET_ACCESS_KEY=''\n",
    ]);

    expect(desktopTreeRefusals($root))->toBe([]);
});

// The directory that carried the key, refused as a shape rather than as one
// file: the exclusion that drops it is the thing this proves still holds.
it('refuses a vendored package dev-tooling directory', function (): void {
    $root = desktopTreeOf([
        'Contents/Resources/build/app/vendor/brick/money/tools/ecs/ecs.php' => '<?php // ecs',
    ]);

    expect(desktopTreeRefusals($root))->toHaveCount(1)
        ->and(desktopTreeRefusals($root)[0])->toContain("a vendored package's dev tooling");
});

// `tools` is a legal package name, and refusing one would be refusing a
// dependency the application boots on.
it('accepts a vendored package whose own name is tools', function (): void {
    $root = desktopTreeOf([
        'Contents/Resources/build/app/vendor/acme/tools/src/Runner.php' => '<?php // runtime',
    ]);

    expect(desktopTreeRefusals($root))->toBe([]);
});

it('fails the command when the bundle is not there at all', function (): void {
    $this->artisan(InspectDesktopBundleCommand::class, ['path' => sys_get_temp_dir().'/desktop-tree-absent'])
        ->expectsOutputToContain('no bundle at')
        ->assertExitCode(1);
});

// A walk that found nothing prints what a clean bundle prints, and only one of
// them means the build produced something.
it('fails the command on a directory holding no file at all', function (): void {
    $root = sys_get_temp_dir().'/desktop-tree-'.bin2hex(random_bytes(6));
    mkdir($root.'/Contents/MacOS', 0700, true);

    $this->artisan(InspectDesktopBundleCommand::class, ['path' => $root])
        ->expectsOutputToContain('found no file at all')
        ->assertExitCode(1);
});

it('passes the command on a clean bundle, and says how much it read', function (): void {
    $root = desktopTreeOf([
        'Contents/Resources/build/app/artisan' => '<?php // artisan',
        'Contents/Resources/build/cacert.pem' => desktopPemBlock('CERTIFICATE'),
    ]);

    $this->artisan(InspectDesktopBundleCommand::class, ['path' => $root])
        ->expectsOutputToContain('Read 2 files')
        ->assertExitCode(0);
});
