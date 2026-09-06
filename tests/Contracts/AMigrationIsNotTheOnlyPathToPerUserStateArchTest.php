<?php

declare(strict_types=1);

use Modules\Auth\Public\Actions\SignupAction;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;

// A migration establishes state once, for the rows that existed when it ran.
// `beatrax:install` migrates BEFORE it creates its user, so a per-user sweep
// living in a migration walks an empty table on a fresh install and every
// reader since holds the column null — while the migration itself genuinely
// ran and genuinely succeeded, which is why nothing reported it.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-per-user-initialisation-only-a-migration-performs

// Two readings of the same rule. The first is structural: a class a migration
// executes must also be reachable from the running app, or the migration is
// the only thing that ever runs it. The second is the outcome: a user who
// signed up the way a reader does must not still match the "not done yet"
// predicate any such sweep gates itself on.
const MIGRATION_EXECUTION_CALLS = ['make', 'app'];

// Container registration says how to build a class, not that anything builds
// it, and an import says even less. Both are dropped before a file is read as
// a caller, or a `singleton()` line in a service provider would answer for the
// runtime path that does not exist.
const MIGRATION_ONLY_NON_CALLS = [
    '->bind(', '->bindIf(', '->singleton(', '->singletonIf(', '->scoped(', '->instance(', '->tag(',
];

// Each entry names a class only a migration executes, why that is the whole
// truth for it, how many migrations execute it, and `proves` regexes re-run
// against the class's own file — so a class that stops being what was written
// about it fails here rather than sitting under a stale justification.
/** @var array<class-string, array{reason: string, sites: int, proves: list<string>}> */
const MIGRATION_ONLY_EXECUTION_PINS = [
    'Modules\EmailScan\Database\Seeders\IcsStatementSenderSeeder' => [
        'reason' => 'Seeds system known_senders rows at user_id NULL, which every reader on '
            .'the device reads through `user_id = ? OR user_id IS NULL`. Its migration is '
            .'dated after the squashed schema, so a fresh install runs it before the user '
            .'exists and the rows are still there when they do. The merge registry states '
            .'the same thing from the sync side: a NULL user_id row is an application seed '
            .'every install already has, and never travels.',
        'sites' => 1,
        'proves' => [
            '/final class IcsStatementSenderSeeder extends Seeder/',
            "/'user_id' => null,/",
            "/->table\\('known_senders'\\)->insert\\(/",
        ],
    ],
    'Modules\FX\Internal\Services\SeedBundledExchangeRates' => [
        'reason' => 'Seeds the app-wide rate snapshot bundled with the build, keyed on '
            .'(base, quote, date, source) with no user_id. Its migration is dated after the '
            .'squashed schema, so every install runs it — a fresh one included — and there is '
            .'no per-row initialisation for a later row to miss. What a later BUILD ships a '
            .'fresher snapshot for is FetchFxRatesJob, and a reader who never opts into '
            .'network fetches keeps the snapshot they installed with.',
        'sites' => 1,
        'proves' => [
            '/final readonly class SeedBundledExchangeRates/',
            "/table\\('exchange_rates'\\)->upsert\\(/",
        ],
    ],
];

// Not exemptions, and spelled differently on purpose: a debt must not be able
// to decay into a justification. An entry would name a class only a migration
// executes that SHOULD have a runtime caller, the owner blocked on writing it,
// and a count — so the entry goes red the moment its owner adds the caller,
// which is how this table empties itself. Empty, and the stronger for it.
/** @var array<class-string, array{owner: string, sites: int, proves: list<string>}> */
const MIGRATION_ONLY_EXECUTION_HANDOVERS = [];

// A per-user sweep gates itself on "this user is not done yet". Nothing is
// pinned: the whole point of the rule is that a fresh signup must satisfy every
// such gate, and a column that legitimately stays null after signup is a column
// no migration sweeps on.
/** @var array<string, array{reason: string, proves: list<string>}> */
const PER_USER_SWEEP_GATE_PINS = [];

// The third reading. Establishing per-user state on a seam is worth nothing to
// a reader created by a path that does not reach the seam, and `AddUserAction`
// minted a partner without dispatching anything at all — no categorization
// rules, no starting wizard, no envelope genesis, all silently.
const USER_ROW_CREATE_PATTERNS = [
    '/\\bUser::(?:query\\(\\)->|withoutGlobalScopes\\(\\)->)?(?:create|forceCreate|updateOrCreate|firstOrCreate)\\(/',
    "/table\\('users'\\)->(?:insert|insertGetId|updateOrInsert)\\(/",
];

// A factory is a test fixture that happens to live under `Database/`, and a
// fixture user is exactly the reader this rule is not about: the test decides
// what state it wants. Each entry re-proves that the file is still a factory.
/** @var array<string, array{reason: string, proves: list<string>}> */
const USER_ROW_CREATE_PINS = [
    'Modules/Auth/Database/Factories/UserRecoveryCodeFactory.php' => [
        'reason' => 'A model factory, reached only from tests: it mints an owner for the '
            .'recovery-code row under test and nothing about that user is read back, so the '
            .'per-user seeding a real reader needs would be setup a test never asked for.',
        'proves' => ['/extends Factory/', '/class UserRecoveryCodeFactory/'],
    ],
    'Modules/EmailScan/Database/Factories/OAuthSecretFactory.php' => [
        'reason' => 'A model factory, reached only from tests: it mints an owner for the '
            .'oauth-secret row under test, for the same reason as the sibling entry above, '
            .'and dispatching an install event from a factory would seed a corpus into '
            .'every test that touches an inbox.',
        'proves' => ['/extends Factory/', '/class OAuthSecretFactory/'],
    ],
];

/** @return list<string> every migration in this checkout, module and framework alike */
function perUserSweepMigrationFiles(): array
{
    $paths = [];

    foreach ([base_path('Modules'), base_path('database/migrations')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }

            if (str_contains($path, '/Database/Migrations/') || str_contains($path, '/database/migrations/')) {
                $paths[] = $path;
            }
        }
    }

    sort($paths);

    return array_values(array_unique($paths));
}

/**
 * PSR-4 over `Modules/`, so the namespace IS the path. A class that does not
 * resolve to a file here is the framework's or a vendor's, and a migration
 * resolving one of those is not this shape.
 */
function perUserSweepClassFile(string $fqcn): ?string
{
    $path = base_path(str_replace('\\', '/', $fqcn).'.php');

    return is_file($path) ? $path : null;
}

/**
 * The classes each migration resolves from the container, read through the
 * file's own imports so a short name is never guessed at.
 *
 * @param  list<string>  $paths
 * @return array<class-string, list<string>> fqcn => the migrations executing it
 */
function classesMigrationsExecute(array $paths): array
{
    $executed = [];

    foreach ($paths as $path) {
        $source = (string) file_get_contents($path);
        $relative = str_replace(base_path().'/', '', $path);

        $imports = [];
        $useMatches = PatternScan::all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $source);

        foreach ($useMatches[1] as $import) {
            $short = substr((string) strrchr('\\'.$import, '\\'), 1);
            $imports[$short] = $import;
        }

        $calls = implode('|', MIGRATION_EXECUTION_CALLS);
        $matches = PatternScan::all('/(?:'.$calls.')\(\s*([A-Za-z0-9_\\\\]+)::class/', $source);

        foreach ($matches[1] as $name) {
            $fqcn = $imports[$name] ?? ltrim($name, '\\');
            $executed[$fqcn][] = $relative;
        }
    }

    foreach ($executed as $fqcn => $sites) {
        $executed[$fqcn] = array_values(array_unique($sites));
    }

    ksort($executed);

    return $executed;
}

/**
 * The names that count as "this class is reached": its own, plus every
 * interface it declares, because a caller type-hints the contract and the
 * binding that connects the two is registration rather than a call.
 *
 * @return list<string>
 */
function perUserSweepReachableNames(string $fqcn, string $classFile): array
{
    $short = substr((string) strrchr('\\'.$fqcn, '\\'), 1);
    $names = [$short];

    $source = (string) file_get_contents($classFile);
    if (preg_match('/\bclass\s+'.preg_quote($short, '/').'\b[^{]*\bimplements\s+([^{]+)\{/s', $source, $match) === 1) {
        foreach (explode(',', $match[1]) as $contract) {
            $contract = trim($contract);
            if ($contract !== '') {
                $names[] = substr((string) strrchr('\\'.$contract, '\\'), 1);
            }
        }
    }

    return array_values(array_unique($names));
}

/**
 * Comments are stripped first, the way every grep-style invariant in this tree
 * reads a file: a docblock naming the class it replaced, or a `// see
 * SeedBundledExchangeRates` above the code that no longer calls it, would
 * otherwise answer for the runtime caller that is missing.
 *
 * @param  list<string>  $names
 */
function perUserSweepIsReachedFrom(string $path, array $names): bool
{
    $source = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));
    $lines = explode("\n", $source);

    foreach ($lines as $line) {
        if (preg_match('/^\s*use\s/', $line) === 1) {
            continue;
        }

        foreach (MIGRATION_ONLY_NON_CALLS as $registration) {
            if (str_contains($line, $registration)) {
                continue 2;
            }
        }

        foreach ($names as $name) {
            if (preg_match('/\b'.preg_quote($name, '/').'\b/', $line) === 1) {
                return true;
            }
        }
    }

    return false;
}

/**
 * The "not done yet" predicates a per-user sweep gates itself on: a NULL check
 * on a `users` column, in the same statement as the table it reads. Statements
 * rather than lines, because the builder chain that carries both spans several.
 *
 * @param  list<string>  $paths
 * @return array<string, list<string>> column => the files gating on it
 */
function perUserSweepGates(array $paths): array
{
    $gates = [];

    foreach ($paths as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (explode(';', (string) file_get_contents($path)) as $statement) {
            if (! str_contains($statement, "table('users')")) {
                continue;
            }

            $matches = PatternScan::all('/\b(?:or)?[Ww]hereNull\(\s*\'(\w+)\'/', $statement);

            foreach ($matches[1] as $column) {
                $gates[$column][] = $relative;
            }
        }
    }

    foreach ($gates as $column => $files) {
        $gates[$column] = array_values(array_unique($files));
    }

    ksort($gates);

    return $gates;
}

/**
 * The pinned gate columns nothing sweeps on. Read over data rather than over
 * the tree, so the reader can be driven with a fabricated pair while the pin
 * list itself is empty.
 *
 * @param  array<string, list<string>>  $gates
 * @param  list<string>  $pinned
 * @return list<string>
 */
function perUserSweepUnreachedGatePins(array $gates, array $pinned): array
{
    return array_values(array_filter(
        $pinned,
        static fn (string $column): bool => ! array_key_exists($column, $gates),
    ));
}

/**
 * Every production file that writes a row into `users`.
 *
 * @param  list<string>  $paths
 * @return list<string>
 */
function usersRowCreators(array $paths): array
{
    $creators = [];

    foreach ($paths as $path) {
        $source = (string) file_get_contents($path);

        foreach (USER_ROW_CREATE_PATTERNS as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $creators[] = str_replace(base_path().'/', '', $path);

                break;
            }
        }
    }

    sort($creators);

    return array_values(array_unique($creators));
}

it('never leaves a migration as the only thing that runs a class', function (): void {
    $migrations = perUserSweepMigrationFiles();
    expect(count($migrations))->toBeGreaterThan(
        150,
        'Read '.count($migrations).' migrations, too few for an empty offender list to mean anything.',
    );

    $executed = classesMigrationsExecute($migrations);
    $production = BackendSourceFiles::all();
    expect($production)->not->toBeEmpty('The production walk read no file at all, so every class would read as migration-only.');

    $offenders = [];
    $pinned = [];
    $handed = [];
    $walked = 0;

    foreach ($executed as $fqcn => $sites) {
        $classFile = perUserSweepClassFile($fqcn);
        if ($classFile === null) {
            continue;
        }

        $walked++;
        $names = perUserSweepReachableNames($fqcn, $classFile);

        foreach ($production as $candidate) {
            if ($candidate !== $classFile && perUserSweepIsReachedFrom($candidate, $names)) {
                continue 2;
            }
        }

        $pin = MIGRATION_ONLY_EXECUTION_PINS[$fqcn] ?? null;
        if ($pin !== null) {
            $pinned[$fqcn] = true;

            if (count($sites) !== $pin['sites']) {
                $offenders[] = $fqcn.' is pinned at '.$pin['sites'].' executing migrations and now has '
                    .count($sites).': '.implode(', ', $sites);
            }

            continue;
        }

        $handover = MIGRATION_ONLY_EXECUTION_HANDOVERS[$fqcn] ?? null;
        if ($handover === null) {
            $offenders[] = $fqcn.' is executed only by '.implode(', ', $sites);

            continue;
        }

        $handed[$fqcn] = true;

        if (count($sites) !== $handover['sites']) {
            $offenders[] = $fqcn.' is handed to '.$handover['owner'].' at '.$handover['sites']
                .' executing migrations and now has '.count($sites).' — give it a runtime caller and delete the entry';
        }
    }

    // Below the count of module classes migrations actually execute, so a walk
    // that stops reading fails here instead of reporting a clean tree.
    expect($walked)->toBeGreaterThan(3, 'Resolved '.$walked.' executed classes to a file, too few to have read the migrations.');

    expect($offenders)->toBe([], implode("\n  ", [
        'A migration runs once, over the rows that existed when it ran. A class only a',
        'migration executes therefore establishes its state for those rows and for nothing',
        'created afterwards — and `beatrax:install` migrates before it creates its user, so',
        'on a fresh install "afterwards" is everybody. Give it a runtime caller on the seam',
        'every user-creation path already goes through (UserInstalled), or pin it here with',
        'the reason its state cannot be missed.',
        'Offenders:',
        ...$offenders,
    ]));

    expect(array_keys($pinned))->toBe(
        array_keys(MIGRATION_ONLY_EXECUTION_PINS),
        'a pin nobody reaches is a claim about the tree that stopped being true',
    );
    expect(array_keys($handed))->toBe(
        array_keys(MIGRATION_ONLY_EXECUTION_HANDOVERS),
        'a handover nobody reaches has been given its runtime caller — delete the entry',
    );
});

it('leaves a reader who signed up matching no per-user sweep gate', function (): void {
    $migrations = perUserSweepMigrationFiles();
    $sources = $migrations;

    foreach (array_keys(classesMigrationsExecute($migrations)) as $fqcn) {
        $classFile = perUserSweepClassFile($fqcn);
        if ($classFile !== null) {
            $sources[] = $classFile;
        }
    }

    $gates = perUserSweepGates($sources);

    // Derived, not listed: the columns come out of the sweeps themselves, so a
    // sweep added tomorrow is covered without anyone remembering this file.
    expect($gates)->not->toBeEmpty('No sweep gate was read at all, so a reader answering every one of them proves nothing.');
    expect($gates)->toHaveKey('envelope_activated_at');

    /** @var SignupAction $signup */
    $signup = app(SignupAction::class);
    $user = $signup('gate-walker', 'a-long-password-12chars')['user'];

    $row = (array) app('db')->connection()->table('users')->where('id', $user->id)->first();

    $unestablished = [];
    foreach ($gates as $column => $files) {
        if (array_key_exists($column, PER_USER_SWEEP_GATE_PINS)) {
            continue;
        }

        if (! array_key_exists($column, $row)) {
            $unestablished[] = $column.' is swept on by '.implode(', ', $files).' but is not a column on `users`';

            continue;
        }

        if ($row[$column] === null) {
            $unestablished[] = 'users.'.$column.' is still null for a reader who signed up, and '
                .implode(', ', $files).' gates its per-user work on exactly that';
        }
    }

    expect($unestablished)->toBe([], implode("\n  ", [
        'These columns are how a migration-time sweep asks "is this user done yet?", and a',
        'reader created the way readers are created still answers no. The sweep already ran',
        '— before they existed — so nothing will ever come back for them. Establish the',
        'state on the UserInstalled seam every user-creation path dispatches, calling the',
        'same code the sweep calls rather than a second copy of it.',
        'Offenders:',
        ...$unestablished,
    ]));

    // Not toHaveKey(): its second argument is the expected VALUE, so the message
    // written there was being asserted as the column's list of files, and the
    // empty pin list is what kept anyone from finding out.
    expect(perUserSweepUnreachedGatePins($gates, array_keys(PER_USER_SWEEP_GATE_PINS)))->toBe(
        [],
        'PER_USER_SWEEP_GATE_PINS names a column no sweep gates on, so the pin excuses nothing — delete the entry.',
    );
});

it('still holds each pinned and handed-over class to what was written about it', function (): void {
    /** @var array<class-string, array{sites: int, proves: list<string>}> $claims */
    $claims = array_merge(MIGRATION_ONLY_EXECUTION_PINS, MIGRATION_ONLY_EXECUTION_HANDOVERS);
    $reproved = 0;

    foreach ($claims as $fqcn => $claim) {
        $classFile = perUserSweepClassFile($fqcn);
        expect($classFile)->not->toBeNull($fqcn.' no longer resolves to a file in this checkout');

        $source = (string) file_get_contents((string) $classFile);

        foreach ($claim['proves'] as $pattern) {
            expect($source)->toMatch($pattern, $fqcn.' no longer reads the way this entry describes it');
        }

        $reproved++;
    }

    // Counted rather than left implicit, so this states something with an empty
    // table too: it is an assertion, not a loop that quietly does nothing.
    expect($reproved)->toBe(
        count($claims),
        'every pin and handover must be re-proved against the class it was written for',
    );

    foreach (USER_ROW_CREATE_PINS as $relative => $claim) {
        $source = (string) file_get_contents(base_path($relative));

        foreach ($claim['proves'] as $pattern) {
            expect($source)->toMatch($pattern, $relative.' no longer reads the way this entry describes it');
        }
    }

    foreach (array_merge(PER_USER_SWEEP_GATE_PINS, MIGRATION_ONLY_EXECUTION_PINS, USER_ROW_CREATE_PINS) as $claim) {
        expect(strlen($claim['reason']))->toBeGreaterThan(80, 'a pin whose reason fits on one line has not given one');
    }
});

it('dispatches the install event from every path that creates a reader', function (): void {
    $creators = usersRowCreators(BackendSourceFiles::all());

    // Below the number of paths that mint a `users` row today, so a walk that
    // matched nothing cannot report a tree where every path is covered.
    expect(count($creators))->toBeGreaterThan(
        3,
        'Found '.count($creators).' paths that create a reader, too few for an empty offender list to mean anything.',
    );

    $offenders = [];
    $pinned = [];

    foreach ($creators as $relative) {
        if (array_key_exists($relative, USER_ROW_CREATE_PINS)) {
            $pinned[$relative] = true;

            continue;
        }

        // Comments stripped: a file naming the event in a docblock while
        // dispatching nothing is the shape AddUserAction shipped as.
        $source = PatternScan::replace(
            '#/\*.*?\*/|//[^\n]*#s',
            '',
            (string) file_get_contents(base_path($relative)),
        );

        if (! str_contains($source, 'UserInstalled')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'These create a reader and tell nothing about it. Every piece of per-user state the',
        'app expects — the categorization rules, the onboarding wizard, the tax corpus, the',
        'envelope genesis anchor — is written by a listener on UserInstalled, so a reader',
        'minted without it has none of it and no screen says so. Dispatch the event; do not',
        'reproduce what its listeners do.',
        'Offenders:',
        ...$offenders,
    ]));

    expect(array_keys($pinned))->toBe(
        array_keys(USER_ROW_CREATE_PINS),
        'a pin nobody reaches names a file that no longer creates a reader — delete the entry',
    );
});

it('reads a planted sweep and its runtime caller, and is not fooled by a binding', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'sweep').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        use Modules\Planted\Public\Services\PlantedSweepService;
        return new class extends Migration
        {
            public function up(): void
            {
                Container::getInstance()->make(PlantedSweepService::class)->run();
                $this->db()->connection()
                    ->table('users')
                    ->whereNull('planted_swept_at')
                    ->whereNotNull('planted_kept_at')
                    ->update(['planted_swept_at' => 'now']);
            }
        };
        PHP);

    $registration = tempnam(sys_get_temp_dir(), 'sweep-provider').'.php';
    file_put_contents($registration, <<<'PHP'
        <?php
        use Modules\Planted\Public\Services\PlantedSweepService;
        final class PlantedProvider
        {
            public function register(): void
            {
                $this->app->singleton(PlantedSweepService::class);
            }
        }
        PHP);

    $caller = tempnam(sys_get_temp_dir(), 'sweep-caller').'.php';
    file_put_contents($caller, <<<'PHP'
        <?php
        final class PlantedListener
        {
            public function __construct(private PlantedSweepService $sweep) {}
        }
        PHP);

    try {
        $executed = classesMigrationsExecute([$planted]);
        $gates = perUserSweepGates([$planted]);
        $names = ['PlantedSweepService'];
        $seenThroughRegistration = perUserSweepIsReachedFrom($registration, $names);
        $seenThroughCaller = perUserSweepIsReachedFrom($caller, $names);
    } finally {
        @unlink($planted);
        @unlink($registration);
        @unlink($caller);
    }

    expect(array_keys($executed))->toBe(
        ['Modules\Planted\Public\Services\PlantedSweepService'],
        'the class a migration resolves from the container is read through the migration own imports',
    );
    expect(array_keys($gates))->toBe(
        ['planted_swept_at'],
        'the "not done yet" predicate is the whereNull in the same statement as the users table',
    );
    expect($seenThroughRegistration)->toBeFalse(
        'a container binding says how to build a class, not that anything builds it, or a singleton() line would answer for the runtime path that does not exist',
    );
    expect($seenThroughCaller)->toBeTrue(
        'a constructor type-hint is a caller, and the whole rule turns on telling the two apart',
    );

    expect(perUserSweepUnreachedGatePins(
        ['planted_swept_at' => ['Modules/Planted/Database/Migrations/2026_01_01_000000_sweep.php']],
        ['planted_swept_at', 'planted_never_swept_at'],
    ))->toBe(
        ['planted_never_swept_at'],
        'a gate pin naming a column nothing sweeps on excuses nothing; the empty pin list must not be what hides the reader',
    );
});
