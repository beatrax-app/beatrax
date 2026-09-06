<?php

declare(strict_types=1);

use Tests\Contracts\Support\BackendSourceFiles;

// The phone dials out and stops. Neither mobile OS lets an application hold a
// listening socket while backgrounded, so a listener written here is not a
// design choice somebody could revisit — it is a socket the OS closes and a
// peer that can never reach the device.

// The containment map already lets Modules/Mobile/Internal/Sync reach Amp,
// because that is where the outbound websocket client lives. It separates
// packages, not the client half of one from the server half, so nothing there
// notices the day a mobile file asks amphp to accept a connection instead.
const PHONE_LISTENER_SPELLINGS = [
    'Amp\Http\Server',
    'Amp\Websocket\Server',
    'SocketHttpServer',
    'Rfc6455Acceptor',
    'stream_socket_server',
    'socket_create',
    'socket_listen',
    'socket_bind',
    'pcntl_fork',
    'ChildProcess',
];

// Named rather than counted: a walk that lost a root would still find files,
// and the count it reported would look every bit as healthy.
const PHONE_SHELL_WITNESSES = [
    'Modules/Mobile/Internal/Sync/LanSyncClient.php',
    'bootstrap/app.php',
];

/** @return list<string> the directories the phone's own code lives in, from either composer root */
function phoneShellRoots(): array
{
    $roots = [];

    $module = base_path('Modules/Mobile');
    if (is_dir($module)) {
        $roots[] = $module;
    }

    // Nested under the desktop checkout, and the base path itself when the
    // suite runs from the phone's own composer root.
    $shell = is_dir(base_path('mobile-app')) ? base_path('mobile-app') : base_path();

    foreach ((array) scandir($shell) as $entry) {
        if (! is_string($entry) || $entry === '.' || $entry === '..') {
            continue;
        }

        $path = $shell.'/'.$entry;

        // The shell's app/, Modules/, routes/ and tests/ are symlinks into the
        // shared tree, and they are named as well as tested for: a checkout
        // that materialised them as real directories would otherwise put the
        // desktop's own daemon inside the phone's walk.
        $shared = ['Modules', 'app', 'routes', 'resources', 'public', 'tests'];

        // Not the phone's own code: two are somebody else's packages, one is
        // runtime state the build writes, and one holds signing material that
        // never reaches a device at all. A listener spelling found in any of
        // them would name a file no branch here wrote.
        $notOurs = ['vendor', 'node_modules', 'storage', 'build-secrets'];

        if (! is_dir($path) || is_link($path) || in_array($entry, $shared, true) || in_array($entry, $notOurs, true)) {
            continue;
        }

        $roots[] = $path;
    }

    return $roots;
}

/**
 * @param  list<string>  $roots
 * @return list<string> every production PHP file under those roots
 */
function phoneShellSources(array $roots): array
{
    $files = [];

    foreach ($roots as $root) {
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            $path = $file->getPathname();

            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }

            // A test names a listener spelling on purpose — that is how the
            // desktop daemon's own suite is written — and vendor is somebody
            // else's package rather than phone-side code that ships as ours.
            if (str_contains($path, '/tests/') || str_contains($path, '/vendor/')) {
                continue;
            }

            $files[] = $path;
        }
    }

    sort($files);

    return array_values(array_unique($files));
}

/**
 * @param  list<string>  $files
 * @return list<string> `relative/path.php names X` for every listener spelling found
 */
function phoneListenerOffenders(array $files): array
{
    $offenders = [];

    foreach ($files as $path) {
        $source = implode('', array_map(
            static fn (array|string $token): string => is_array($token) ? $token[1] : $token,
            BackendSourceFiles::codeTokens($path),
        ));

        foreach (PHONE_LISTENER_SPELLINGS as $spelling) {
            if (str_contains($source, $spelling)) {
                $offenders[] = str_replace(base_path().'/', '', $path).' names '.$spelling;
            }
        }
    }

    sort($offenders);

    return $offenders;
}

/** The phone's own composer manifest, from whichever root the suite is running at */
function phoneShellManifestPath(string $file): string
{
    $nested = base_path('mobile-app/'.$file);

    return is_file($nested) ? $nested : base_path($file);
}

it('ships no phone-side code that binds a socket or outlives the request that started it', function (): void {
    $files = phoneShellSources(phoneShellRoots());

    foreach (PHONE_SHELL_WITNESSES as $witness) {
        expect(array_filter($files, static fn (string $path): bool => str_ends_with($path, $witness)))
            ->not->toBe([], "the walk never reached {$witness}, so a clean answer here is a walk that read nothing");
    }

    $offenders = phoneListenerOffenders($files);

    expect($offenders)->toBe(
        [],
        "Phone-side code reached for a listener or a persistent process:\n  ".implode("\n  ", $offenders)."\n\n".
        "A phone is a full peer in the merge and a client in transport: it opens a connection, exchanges,\n".
        "and closes. Neither mobile OS keeps a listening socket or a background process alive once the app\n".
        "is backgrounded, so what is written here does not merely go unused — it fails in a way no screen\n".
        'can report. Dial out through LanSyncClient, or drain the relay mailbox.',
    );
});

it('spawns the long-running daemons only from the shell the phone switches off', function (): void {
    $spawners = [];

    foreach (BackendSourceFiles::all() as $path) {
        $source = implode('', array_map(
            static fn (array|string $token): string => is_array($token) ? $token[1] : $token,
            BackendSourceFiles::codeTokens($path),
        ));

        if (str_contains($source, 'ChildProcess::')) {
            $spawners[] = str_replace(base_path().'/', '', $path);
        }
    }

    sort($spawners);

    expect($spawners)->not->toBe([], 'nothing spawns a child process at all, so this guard read nothing');

    $outsideDesktop = array_values(array_filter(
        $spawners,
        static fn (string $path): bool => ! str_starts_with($path, 'Modules/Desktop/'),
    ));

    expect($outsideDesktop)->toBe(
        [],
        "A persistent child process is spawned outside the desktop shell:\n  ".implode("\n  ", $outsideDesktop)."\n\n".
        "sync:serve and relay:serve are listeners, and the only thing keeping them off the phone is that\n".
        "nothing the phone loads can start one. Modules/Desktop is switched off in the phone's module\n".
        'roster; a spawn from any other module ships to the device with it.',
    );
});

it('keeps the desktop shell out of the roster the phone boots, and out of its dependencies', function (): void {
    $statuses = json_decode((string) file_get_contents(phoneShellManifestPath('modules_statuses.json')), true);
    expect($statuses)->toBeArray('modules_statuses.json did not decode to an array, so every assertion below it is about null.');

    /** @var array<string, mixed> $statuses */
    expect(array_key_exists('Desktop', $statuses))->toBeTrue(
        'the phone roster no longer mentions the desktop module, so nothing there records that it stays off',
    );
    expect($statuses['Desktop'])->toBeFalse(
        'the module that spawns sync:serve and relay:serve is enabled on the phone',
    );

    $manifest = json_decode((string) file_get_contents(phoneShellManifestPath('composer.json')), true);
    expect($manifest)->toBeArray('the phone shell composer.json did not decode to an array, so every assertion below it is about null.');

    /** @var array{name?: string, require?: array<string, string>} $manifest */
    expect($manifest['name'] ?? '')->toBe(
        'beatrax/beatrax-mobile',
        'the phone shell manifest was not found, so the two assertions below would be about the desktop',
    );

    $require = $manifest['require'] ?? [];

    expect(array_key_exists('nativephp/mobile', $require))->toBeTrue(
        'the phone shell no longer requires nativephp/mobile, so this manifest is not the one that builds the device bundle.',
    );

    // The two packages conflict, so a desktop requirement here would not merely
    // ship the daemon's shell to the phone — it would not install at all.
    expect(array_key_exists('nativephp/desktop', $require))->toBeFalse(
        'the phone shell requires nativephp/desktop, which is the package that ships the update poller and the daemon shell.',
    );
});

it('sees a listener planted in phone-side code', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'phone-listener').'.php';
    file_put_contents($planted, <<<'PHP_SOURCE'
        <?php
        // Amp\Http\Server named in a comment must not count.
        final class PlantedPhoneListener
        {
            public function listen(int $port): void
            {
                $server = \Amp\Http\Server\SocketHttpServer::createForDirectAccess(null);
                $server->expose('0.0.0.0:'.$port);
            }
        }
        PHP_SOURCE);

    try {
        $found = phoneListenerOffenders([$planted]);
    } finally {
        @unlink($planted);
    }

    expect($found)->toBe(
        [
            str_replace(base_path().'/', '', $planted).' names Amp\Http\Server',
            str_replace(base_path().'/', '', $planted).' names SocketHttpServer',
        ],
        'The reader must find both spellings in the code and neither of the two in the comment '
        .'above it — a guard that counted prose would pass on a file that only mentions a listener '
        .'and fail on a file that only explains one.',
    );
});
