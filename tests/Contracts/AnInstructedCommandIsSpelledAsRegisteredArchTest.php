<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 */

/**
 * Every command name the artisan application answers to, exactly as registered.
 *
 * @return list<string>
 */
function instructedCommandRegisteredNames(): array
{
    /** @var list<string> $names */
    $names = array_keys(Artisan::all());
    sort($names);

    return $names;
}

/**
 * Directories holding no hand-written instruction: third-party code, build
 * output, caches, generated NativePHP scaffolding, and the agent tooling under
 * .claude, whose worktrees are whole second checkouts of this repository.
 *
 * @return list<string>
 */
function instructedCommandSkippedDirectories(): array
{
    return [
        '.git', '.claude', '.phpstan-cache', '.phpunit.cache', 'vendor', 'node_modules',
        'build', 'cache', 'storage', 'snapshots', 'nativephp', 'dist', 'coverage',
    ];
}

/**
 * @param  list<string>  $extensions
 * @return list<string> absolute paths
 */
function instructedCommandFilesUnder(string $root, array $extensions): array
{
    if (! is_dir($root)) {
        return [];
    }

    $skipped = instructedCommandSkippedDirectories();

    $pruned = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        static fn (SplFileInfo $file): bool => ! $file->isDir() || ! in_array($file->getFilename(), $skipped, true),
    );

    $files = [];
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator($pruned) as $file) {
        if (! $file->isFile() || $file->getFilename() === basename(__FILE__)) {
            continue;
        }
        foreach ($extensions as $extension) {
            if (str_ends_with($file->getPathname(), $extension)) {
                $files[] = $file->getPathname();
                break;
            }
        }
    }
    sort($files);

    return $files;
}

/**
 * Translation files and documentation — the two surfaces that hand a reader a
 * command to type.
 *
 * @return list<string> absolute paths
 */
function instructedCommandSources(): array
{
    $files = instructedCommandFilesUnder(base_path('.docs'), ['.md']);
    $files = array_merge($files, instructedCommandFilesUnder(base_path('lang'), ['.php']));

    foreach (glob(base_path('Modules/*/Resources/lang')) ?: [] as $langRoot) {
        $files = array_merge($files, instructedCommandFilesUnder($langRoot, ['.php']));
    }

    foreach (glob(base_path('*.md')) ?: [] as $page) {
        if (basename($page) !== basename(__FILE__)) {
            $files[] = $page;
        }
    }

    sort($files);

    return array_values($files);
}

/**
 * The `php artisan <name>` invocations in $contents, as [line, name].
 *
 * A block that changes composer root first — `cd mobile-app` — is instructing a
 * different artisan application, whose commands this one does not register.
 *
 * @return list<array{int, string}>
 */
function instructedCommandInvocations(string $contents): array
{
    $found = [];
    $foreignRoot = false;

    foreach (explode("\n", $contents) as $index => $line) {
        if (preg_match('/^\s*```/', $line) === 1) {
            $foreignRoot = false;
        }
        if (preg_match('/(^|[`\s;&])cd\s+mobile-app\b/', $line) === 1) {
            $foreignRoot = true;
        }
        if ($foreignRoot) {
            continue;
        }

        if (preg_match_all('/php\s+artisan\s+([A-Za-z][A-Za-z0-9:_-]*)/', $line, $matches) === 0) {
            continue;
        }
        foreach ($matches[1] as $name) {
            $found[] = [$index + 1, $name];
        }
    }

    return $found;
}

/**
 * The tokens in $contents that are a registered command's name in every respect
 * but case. A token matching nothing is not a claim about a command at all —
 * `beatrax:webauthn-create` is a browser event — so only a case-fold hit counts.
 *
 * @param  array<string, string>  $foldedNames  lowercased name => registered name
 * @return list<array{int, string, string}>
 */
function instructedCommandMiscasings(string $contents, array $foldedNames): array
{
    $found = [];

    foreach (explode("\n", $contents) as $index => $line) {
        if (preg_match_all('/(?<![A-Za-z0-9_\-\/:])([A-Za-z][A-Za-z0-9_-]*:[A-Za-z][A-Za-z0-9:_-]*)/', $line, $matches) === 0) {
            continue;
        }
        foreach ($matches[1] as $token) {
            $registered = $foldedNames[strtolower($token)] ?? null;
            if ($registered !== null && $registered !== $token) {
                $found[] = [$index + 1, $token, $registered];
            }
        }
    }

    return $found;
}

it('instructs no artisan command the application does not register', function (): void {
    $registered = instructedCommandRegisteredNames();
    $sources = instructedCommandSources();

    expect($registered)->not->toBe([]);
    expect($sources)->not->toBe([]);

    $offenders = [];

    foreach ($sources as $path) {
        $label = str_replace(base_path().'/', '', $path);

        foreach (instructedCommandInvocations((string) file_get_contents($path)) as [$line, $name]) {
            if (! in_array($name, $registered, true)) {
                $offenders[] = $label.':'.$line.'  php artisan '.$name;
            }
        }
    }

    expect($offenders)->toBe([], implode("\n  ", array_merge(
        ['A translation or a documentation page hands the reader a command name the artisan',
            'application does not answer to. Comparison is exact: Symfony retries a miscased name',
            'case-insensitively, so a wrong spelling still runs at the shell and reads as correct,',
            'but the Dev Console allow-list compares with === and refuses it. Offenders:'],
        $offenders,
    )));
});

it('writes a registered command name in the case it is registered under, everywhere', function (): void {
    $folded = [];
    foreach (instructedCommandRegisteredNames() as $name) {
        $folded[strtolower($name)] = $name;
    }

    $files = array_merge(
        instructedCommandFilesUnder(base_path('.docs'), ['.md']),
        instructedCommandFilesUnder(base_path('Modules'), ['.php', '.md', '.blade.php']),
        instructedCommandFilesUnder(base_path('app'), ['.php']),
        instructedCommandFilesUnder(base_path('resources'), ['.php', '.blade.php', '.js']),
        instructedCommandFilesUnder(base_path('lang'), ['.php']),
        instructedCommandFilesUnder(base_path('scripts'), ['.php']),
        instructedCommandFilesUnder(base_path('tests'), ['.php']),
    );

    expect($files)->not->toBe([]);

    // The one file that must write a miscased name: it asserts the Dev Console
    // refuses one.
    $deliberate = base_path('Modules/DevMode/tests/Feature/CommandRegistryTest.php');

    $offenders = [];

    foreach ($files as $path) {
        if ($path === $deliberate) {
            continue;
        }
        $label = str_replace(base_path().'/', '', $path);

        foreach (instructedCommandMiscasings((string) file_get_contents($path), $folded) as [$line, $token, $registered]) {
            $offenders[] = $label.':'.$line.'  '.$token.' is registered as '.$registered;
        }
    }

    expect($offenders)->toBe([], implode("\n  ", array_merge(
        ['A command name is written in a case it is not registered under. The brand guard cannot',
            'see this: its pattern ends `(?!:[A-Za-z0-9_*/])`, which exempts every artisan signature',
            'so that `beatrax:install` stays lowercase beside the capitalised brand — and that',
            'exemption is what let a brand sweep capitalise the command token itself. Offenders:'],
        $offenders,
    )));
});

it('offers no Dev Console command the application does not register', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);
    $registered = instructedCommandRegisteredNames();

    $offenders = [];

    foreach (array_merge($registry->safe(), $registry->destructive()) as $spec) {
        if (! in_array($spec->name, $registered, true)) {
            $offenders[] = $spec->name;
        }
    }

    expect($offenders)->toBe([], 'The Dev Console palette offers a command artisan does not register under that '
        ."exact name, so spawning it would fail at the shell rather than at the allow-list.\n  "
        .implode("\n  ", $offenders));
});

it('reads an invocation only where one is written, and only for this composer root', function (): void {
    $sample = <<<'TEXT'
        Run php artisan beatrax:doctor for guidance.
        <code>php artisan db:backup</code>
        php artisan Beatrax:doctor
        the artisan runner spawns a child
        php artisan <command>
        cd mobile-app
        php artisan native:release patch
        TEXT;

    expect(instructedCommandInvocations($sample))->toBe([
        [1, 'beatrax:doctor'],
        [2, 'db:backup'],
        [3, 'Beatrax:doctor'],
    ]);
});

it('reads a miscasing only where the name is a real command', function (): void {
    $folded = ['beatrax:doctor' => 'beatrax:doctor', 'db:backup' => 'db:backup'];

    $sample = <<<'TEXT'
        php artisan Beatrax:doctor
        dispatch('beatrax:webauthn-create')
        php artisan beatrax:doctor
        Note: the DB:Backup schedule runs at 03:00
        TEXT;

    expect(instructedCommandMiscasings($sample, $folded))->toBe([
        [1, 'Beatrax:doctor', 'beatrax:doctor'],
        [4, 'DB:Backup', 'db:backup'],
    ]);
});
