<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Events\Dispatcher;
use Modules\Core\Internal\Build\BuiltFrontEnd;
use Modules\Core\Internal\Build\StaleFrontEndException;
use Modules\Core\Internal\Listeners\RefuseToShipAStaleFrontEnd;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @param  array<string, int>  $sources  repo-relative path => modification time
 */
function buildSeamTree(array $sources, ?int $built): string
{
    $root = sys_get_temp_dir().'/buildseam-'.bin2hex(random_bytes(8));

    foreach ($sources as $relative => $modified) {
        $path = $root.'/'.$relative;
        @mkdir(dirname($path), 0o777, true);
        file_put_contents($path, 'x');
        touch($path, $modified);
    }

    @mkdir($root.'/public', 0o777, true);

    if ($built !== null) {
        @mkdir($root.'/public/build/assets', 0o777, true);
        file_put_contents($root.'/public/build/assets/app-Ab12Cd34.js', 'x');
        touch($root.'/public/build/assets/app-Ab12Cd34.js', $built);
    }

    return $root;
}

function buildSeamRemove(string $directory): void
{
    $tree = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    /** @var SplFileInfo $entry */
    foreach ($tree as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }

    @rmdir($directory);
}

// The one root both Composer roots agree on: from mobile-app/ every path below
// is reached by symlink, and base_path() there names a tree that has no
// .github/ and no scripts/ of its own.
function buildSeamStandaloneCommand(string $root): string
{
    return escapeshellarg(PHP_BINARY)
        .' '.escapeshellarg(buildSeamRepoRoot().'/scripts/refuse_a_stale_front_end.php')
        .' '.escapeshellarg($root.'/public')
        .' materialize.sh 2>&1';
}

function buildSeamRepoRoot(): string
{
    return dirname((string) realpath(base_path('Modules')));
}

function buildSeamRefusal(string $root, string $command): ?string
{
    $listener = new RefuseToShipAStaleFrontEnd(BuiltFrontEnd::beside($root.'/public'));
    $event = new CommandStarting($command, new ArrayInput([]), new BufferedOutput);

    try {
        $listener->handle($event);
    } catch (StaleFrontEndException $refusal) {
        return $refusal->getMessage();
    }

    return null;
}

it('reads the front-end sources this repository actually compiles from', function (): void {
    $sources = BuiltFrontEnd::beside(base_path('public'))->sources();
    $root = dirname((string) realpath(base_path('public'))).'/';
    $relative = array_map(static fn (string $path): string => str_replace($root, '', $path), array_keys($sources));

    expect($relative)->toContain('vite.config.js')
        ->and($relative)->toContain('resources/js/app.js')
        ->and($relative)->toContain('resources/css/app.css');

    $moduleTemplates = array_filter($relative, static fn (string $path): bool => str_starts_with($path, 'Modules/'));

    expect(count($moduleTemplates))->toBeGreaterThan(100, 'Only '.count($moduleTemplates).' module templates were read, so a stale stylesheet would have nothing to be judged against.');
});

it('reports a build newer than every source as fresh', function (): void {
    $root = buildSeamTree(['resources/js/app.js' => 1_700_000_000], 1_700_000_060);

    expect(BuiltFrontEnd::beside($root.'/public')->staleness())->toBeNull();

    buildSeamRemove($root);
});

it('names the source a build predates', function (): void {
    $root = buildSeamTree([
        'resources/js/app.js' => 1_700_000_000,
        'Modules/Ledger/Resources/views/page.blade.php' => 1_700_000_500,
    ], 1_700_000_060);

    $stale = BuiltFrontEnd::beside($root.'/public')->staleness();

    expect($stale)->not->toBeNull()
        ->and($stale?->source)->toBe('Modules/Ledger/Resources/views/page.blade.php')
        ->and($stale?->sourceModified)->toBe(1_700_000_500)
        ->and($stale?->builtModified)->toBe(1_700_000_060);

    buildSeamRemove($root);
});

it('reads a build directory with nothing in it as the absence of a build', function (): void {
    $root = buildSeamTree(['resources/js/app.js' => 1_700_000_000], null);

    $stale = BuiltFrontEnd::beside($root.'/public')->staleness();

    expect($stale?->built)->toBeNull()
        ->and($stale?->sentence('native:run'))->toContain('public/build holds no files');

    buildSeamRemove($root);
});

it('resolves the tree a symlinked public directory points into', function (): void {
    $root = buildSeamTree(['resources/js/app.js' => 1_700_000_500], 1_700_000_060);
    $secondRoot = $root.'-mobile';
    @mkdir($secondRoot, 0o777, true);
    symlink($root.'/public', $secondRoot.'/public');

    expect(BuiltFrontEnd::beside($secondRoot.'/public')->staleness()?->source)
        ->toBe('resources/js/app.js');

    @unlink($secondRoot.'/public');
    @rmdir($secondRoot);
    buildSeamRemove($root);
});

it('refuses every command that puts the built front end on a device', function (string $command): void {
    $root = buildSeamTree(['resources/js/app.js' => 1_700_000_500], 1_700_000_060);

    expect(buildSeamRefusal($root, $command))->toContain('Run `npm run build`', $command);

    buildSeamRemove($root);
})->with(RefuseToShipAStaleFrontEnd::SHIPS_THE_BUILT_FRONT_END);

it('lets every other command through a tree it would refuse to build', function (string $command): void {
    $root = buildSeamTree(['resources/js/app.js' => 1_700_000_500], 1_700_000_060);

    expect(buildSeamRefusal($root, $command))->toBeNull();

    buildSeamRemove($root);
})->with(['migrate', 'config:cache', 'native:install', 'native:serve', 'mobile:inspect-bundle', 'queue:work']);

it('lets a shipping command through once the build is newer than its sources', function (): void {
    $root = buildSeamTree(['resources/js/app.js' => 1_700_000_000], 1_700_000_060);

    expect(buildSeamRefusal($root, 'native:run'))->toBeNull();

    buildSeamRemove($root);
});

it('has the refusal registered on the event both Composer roots raise', function (): void {
    $listeners = app(Dispatcher::class)->getRawListeners();

    expect($listeners[CommandStarting::class] ?? [])->toContain(RefuseToShipAStaleFrontEnd::class);
});

it('refuses a stale tree from a shell that has no autoloader', function (): void {
    $root = buildSeamTree(['resources/js/app.js' => 1_700_000_500], 1_700_000_060);

    exec(buildSeamStandaloneCommand($root), $output, $status);

    expect($status)->toBe(1)
        ->and(implode("\n", $output))->toContain('materialize.sh would ship', 'Run `npm run build`');

    buildSeamRemove($root);
});

it('passes a fresh tree from that same shell', function (): void {
    $root = buildSeamTree(['resources/js/app.js' => 1_700_000_000], 1_700_000_060);

    exec(buildSeamStandaloneCommand($root), $output, $status);

    expect($status)->toBe(0)
        ->and(implode("\n", $output))->toContain('newer than every source');

    buildSeamRemove($root);
});

it('has materialize.sh ask that reader before it copies anything', function (): void {
    $script = (string) file_get_contents(buildSeamRepoRoot().'/mobile-app/scripts/materialize.sh');

    $asks = strpos($script, 'scripts/refuse_a_stale_front_end.php');
    $copies = strpos($script, 'rsync -a --copy-links');

    expect($asks)->not->toBeFalse('materialize.sh no longer asks whether public/build is current.')
        ->and($copies)->not->toBeFalse('The copy this rule orders against was renamed, so the order below proves nothing.')
        ->and($asks)->toBeLessThan($copies);
});

it('sets up a pinned PHP in every workflow that runs materialize.sh', function (): void {
    $missing = [];
    $read = 0;

    foreach ((array) glob(buildSeamRepoRoot().'/.github/workflows/*.yml') as $path) {
        $body = (string) file_get_contents((string) $path);

        if (! str_contains($body, 'materialize.sh')) {
            continue;
        }

        $read++;

        if (! str_contains($body, 'shivammathur/setup-php@')) {
            $missing[] = basename((string) $path);
        }
    }

    expect($read)->toBeGreaterThan(0, 'No workflow runs materialize.sh, so this rule read nothing at all.');
    expect($missing)->toBe([], 'These run materialize.sh, whose front-end check is written in this project\'s PHP, on whatever the runner image happens to carry: '.implode(', ', $missing));
});
