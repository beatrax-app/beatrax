<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

// The one answer to "what does a guard over this repo open?". Every scanner
// used to carry its own root list, which is a claim about the tree that nothing
// re-checked, and the claims drifted apart: the unchecked-preg_match walk
// opened five roots, its preg_replace sibling six, and "every backend PHP file"
// two. The six unchecked replaces rewriting the Android manifest sat in
// scripts/, a root none of the three had ever read.
//
// A scope names the roots it opens and the roots it declines, with the reason
// for each refusal. A root holding first-party files of the scope's kind that
// appears in neither list is the defect this shape exists to make impossible.
/**
 * @link ../../../.docs/conventions/arch-invariants.md#a-scanner-accounts-for-the-whole-tree
 */
final class RepoTree
{
    public const string EVERY_PHP_FILE = 'every PHP file in the repository';

    public const string PRODUCTION_PHP = 'the PHP that ships, the suite aside';

    public const string EVERY_BLADE_VIEW = 'every Blade view a reader is shown';

    public const string RUNTIME_DOMAIN_PHP = 'the code that writes to a database at runtime';

    /**
     * A skip carries its reason for the same reason a decline does: it is a
     * refusal a hundred guards inherit without ever reading it, and the only
     * ones a reader can audit are the ones that say what they are for.
     *
     * @var array<string, array{extension: string, covers: list<string>, declines: array<string, string>, skips: array<string, string>}>
     */
    public const array SCOPES = [
        self::EVERY_PHP_FILE => [
            'extension' => '.php',
            'covers' => ['.claude', 'app', 'bootstrap', 'config', 'database', 'lang', 'mobile-app', 'Modules', 'public', 'resources', 'routes', 'scripts', 'tests', 'tools'],
            'declines' => [],
            'skips' => [],
        ],
        self::PRODUCTION_PHP => [
            'extension' => '.php',
            'covers' => ['.claude', 'app', 'bootstrap', 'config', 'database', 'lang', 'mobile-app', 'Modules', 'public', 'resources', 'routes', 'scripts', 'tools'],
            'declines' => [
                'tests' => 'the suite asserts about production code, and its doubles name the forbidden shapes on purpose, so a rule about shipped behaviour reads its own fixtures as offenders',
            ],
            'skips' => [
                '/tests/' => 'the same refusal as the declined tests root, spelled as a fragment because a module keeps its own suite inside Modules/, which this scope covers',
                '/Database/Migrations/' => 'a migration declares the schema and seeds the first rows, including the columns whose later mutation these rules restrict, so it reads as an offender for doing its job',
                '/migrations/' => 'the same files under the shared database/ root, which spells the directory in lower case',
            ],
        ],
        self::EVERY_BLADE_VIEW => [
            'extension' => '.blade.php',
            'covers' => ['Modules', 'resources'],
            'declines' => [],
            'skips' => [
                '/tests/' => 'a fixture template is not a view a reader is shown, and a guard that plants a violation into one would read its own plant as an offender',
            ],
        ],
        self::RUNTIME_DOMAIN_PHP => [
            'extension' => '.php',
            'covers' => ['app', 'Modules'],
            'declines' => [
                '.claude' => 'editor hooks, which run against this tree and are not part of it',
                'bootstrap' => 'the application assembling itself, never a domain write',
                'config' => 'configuration arrays',
                'database' => 'the shared migrations and seeders: schema and reference data, which is how those rows are meant to arrive',
                'lang' => 'translation arrays',
                'mobile-app' => 'its Modules/ and app/ are symlinks to the two roots already walked',
                'public' => 'the built front end',
                'resources' => 'Blade views and assets',
                'routes' => 'the application assembling itself',
                'scripts' => 'build and release scripts, which never run beside a database',
                'tests' => 'the guards themselves, and the fixtures that name a violation on purpose',
                'tools' => 'toolchain stubs, analysed by nothing at runtime',
            ],
            'skips' => [
                '/tests/' => 'the same refusal as the declined tests root, spelled as a fragment because a module keeps its own suite inside Modules/, which this scope covers',
                '/Database/Migrations/' => 'schema replayed by every test rather than a write against a reader\'s data',
                '/Database/Seeders/' => 'reference and demo rows, which is how those rows are meant to arrive',
                '/Database/Factories/' => 'row builders the suite calls, which never run beside a reader\'s database',
            ],
        ],
    ];

    /**
     * Code this repository did not write, sitting inside it. Each fragment
     * carries the reason it is refused, and the guard beside this class holds
     * every one of them to a directory that exists and to a walk that returns
     * nothing from it, so a refusal that stopped excusing anything fails there.
     *
     * @var array<string, string>
     */
    public const array NEVER_WALKED = [
        '/vendor/' => 'somebody else\'s PHP, fifty thousand files no scope here has anything to say about, and the one root a walk that forgot it spends all its time in',
        '/node_modules/' => 'the same, for the front-end toolchain',
        '/bootstrap/cache/' => 'generated by Laravel under a root every scope covers, and the quieter half of this list: git never held these files, so the accounting below reads its roots out of `git ls-files` and cannot see one. They also name every provider in the application, which reads as each module citing every other',
    ];

    /** @var array<string, list<string>> */
    private static array $walked = [];

    /** @var array<string, list<string>> */
    private static array $tracked = [];

    /**
     * The repository root, which is not `base_path()` under the second Composer
     * root: the mobile job points that at mobile-app/, and a scope asking for
     * "the tree" means the same tree read from either.
     */
    public static function root(): string
    {
        $directory = base_path();

        for ($climb = 0; $climb < 3; $climb++) {
            if (is_file($directory.'/AGENTS.md')) {
                return $directory;
            }

            $directory = dirname($directory);
        }

        throw new RuntimeException('RepoTree cannot find the repository root above '.base_path().': no AGENTS.md within three levels.');
    }

    /**
     * @return list<string> absolute paths, every file the named scope opens
     */
    public static function files(string $scope): array
    {
        return self::$walked[$scope] ??= self::walk($scope);
    }

    /**
     * @return list<string> the same files as paths relative to the repository root
     */
    public static function relativeFiles(string $scope): array
    {
        $prefix = self::root().'/';

        return array_map(static fn (string $path): string => str_replace($prefix, '', $path), self::files($scope));
    }

    /**
     * The three ways a scope can stop describing the tree, answered over data
     * rather than over the filesystem, so the guard can plant one of each and
     * watch the reader find it.
     *
     * @param  array{extension: string, covers: list<string>, declines: array<string, string>, skips: array<string, string>}  $scope
     * @param  list<string>  $tracked  repo-relative paths git says this repository holds
     * @param  list<string>  $walked  repo-relative paths the scope's walk reached
     * @return array{unaccounted: list<string>, stale: list<string>, silent: list<string>}
     */
    public static function account(array $scope, array $tracked, array $walked): array
    {
        $holding = self::rootsIn($tracked, array_keys($scope['skips']));
        $reached = self::rootsIn($walked, []);
        $accounted = [...$scope['covers'], ...array_keys($scope['declines'])];

        $silent = [];

        foreach ($scope['covers'] as $root) {
            if (in_array($root, $holding, true) && ! in_array($root, $reached, true)) {
                $silent[] = $root;
            }
        }

        return [
            'unaccounted' => array_values(array_diff($holding, $accounted)),
            // Measured without the scope's own skips: a decline and a skip are
            // two spellings of one refusal, and letting the second satisfy the
            // first would report every declined root as excusing nothing.
            'stale' => array_values(array_diff(array_keys($scope['declines']), self::rootsIn($tracked, []))),
            'silent' => $silent,
        ];
    }

    /**
     * @return array{unaccounted: list<string>, stale: list<string>, silent: list<string>}
     */
    public static function accountOf(string $scope): array
    {
        $declared = self::scope($scope);

        return self::account($declared, self::tracked($declared['extension']), self::relativeFiles($scope));
    }

    /**
     * Top-level directories holding first-party files with $extension, asked of
     * git rather than of the filesystem. The filesystem answer includes vendor,
     * node_modules and every local analyser cache -- fifty thousand files of
     * somebody else's PHP -- and a list naming those to skip is the drift this
     * class exists to stop. It also settles a worktree's `.git`, which is a file
     * here and a directory in CI, by never enumerating top-level entries at all.
     *
     * @param  list<string>  $skips
     * @return list<string>
     */
    public static function rootsHolding(string $extension, array $skips = []): array
    {
        return self::rootsIn(self::tracked($extension), $skips);
    }

    /**
     * @return array{extension: string, covers: list<string>, declines: array<string, string>, skips: array<string, string>}
     */
    public static function scope(string $scope): array
    {
        return self::SCOPES[$scope] ?? throw new RuntimeException('RepoTree knows no scope named "'.$scope.'".');
    }

    /**
     * @return list<string>
     */
    private static function walk(string $scope): array
    {
        $declared = self::scope($scope);
        $root = self::root();
        $files = [];

        foreach ($declared['covers'] as $name) {
            $directory = $root.'/'.$name;

            if (! is_dir($directory)) {
                continue;
            }

            foreach (self::under($directory) as $path) {
                if (str_ends_with($path, $declared['extension']) && ! self::skipped($path, array_keys($declared['skips']))) {
                    $files[] = $path;
                }
            }
        }

        sort($files);

        $prefix = $root.'/';
        $walked = array_map(static fn (string $path): string => str_replace($prefix, '', $path), $files);
        $silent = self::account($declared, self::tracked($declared['extension']), $walked)['silent'];

        if ($silent !== []) {
            throw new RuntimeException(
                'RepoTree::files("'.$scope.'") reached no file at all under '.implode(', ', $silent)
                .', which git says holds '.$declared['extension'].' this scope does not skip. A scope that stops '
                .'reading a root it claims reports a clean tree over code nobody opened.'
            );
        }

        return $files;
    }

    // A link is never followed. mobile-app is the second Composer root and
    // reaches this same tree through symlinks -- whole directories for Modules
    // and tests, one file at a time for fifteen of its eighteen configs --
    // and following one reports every shared file a second time under a second
    // spelling, which is a count nobody can trust.
    /**
     * @return list<string>
     */
    private static function under(string $directory): array
    {
        $paths = [];

        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($walk as $file) {
            $path = $file->getPathname();

            if ($file->isFile() && ! $file->isLink() && ! self::refuses($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Whether a path is code this repository did not write. Public so the guard
     * beside this class can put a path to the reader itself: the refusal is
     * measured against paths, and asking whether one of these directories
     * happens to hold a file today answers about a build machine rather than
     * about the rule.
     */
    public static function refuses(string $path): bool
    {
        return self::skipped($path, array_keys(self::NEVER_WALKED));
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $skips
     * @return list<string>
     */
    private static function rootsIn(array $paths, array $skips): array
    {
        $roots = [];

        foreach ($paths as $path) {
            $first = explode('/', $path)[0];

            if ($first !== $path && ! self::skipped('/'.$path, $skips)) {
                $roots[$first] = true;
            }
        }

        $named = array_keys($roots);
        sort($named);

        return $named;
    }

    /**
     * @param  list<string>  $fragments
     */
    private static function skipped(string $path, array $fragments): bool
    {
        foreach ($fragments as $fragment) {
            if (str_contains($path, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function tracked(string $extension): array
    {
        return self::$tracked[$extension] ??= self::askGit($extension);
    }

    /**
     * @return list<string>
     */
    private static function askGit(string $extension): array
    {
        $listed = shell_exec(sprintf(
            'git -C %s ls-files -z -- %s 2>/dev/null',
            escapeshellarg(self::root()),
            escapeshellarg('*'.$extension),
        ));

        $paths = is_string($listed) ? array_values(array_filter(explode("\0", $listed))) : [];

        if ($paths === []) {
            throw new RuntimeException(
                'RepoTree asked git which files this repository tracks ending in '.$extension.' and got none back. '
                .'Every scope is accounted for against that answer, so an empty one would let a whole root go '
                .'unscanned with every guard still green. Run the suite from a checkout git can read.'
            );
        }

        return $paths;
    }
}
