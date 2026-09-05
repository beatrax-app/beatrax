<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Modules\Core\Public\Support\PatternScan;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\ArgSpec;
use Modules\DevMode\Public\Dto\CommandSpec;
use Symfony\Component\Console\Command\Command as ConsoleCommand;

// The Dev Console spawns a detached child whose stdin is /dev/null and whose
// command line is built from the registry alone. Three entries could therefore
// never succeed: db:restore named neither flag its own handle() demands,
// db:backup offered a destination artisan refuses as a second positional, and
// beatrax:reset-password asked for a secret nothing could type. A fourth,
// migrate:fresh, could — and it drops every table.
//
// Prose said adding such a command "would be a visible change in review". It
// was, and review carried it for the whole life of the destructive tier, so
// this file is the control instead. Every claim below is settled against the
// LIVE console application by reflection, never against a grep of the source.

/**
 * @return list<CommandSpec>
 */
function devConsoleRegisteredSpecs(): array
{
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);

    return array_merge($registry->safe(), $registry->destructive());
}

/**
 * @return array<string, ConsoleCommand> registered name => the live command
 */
function devConsoleLiveCommands(): array
{
    /** @var array<string, ConsoleCommand> $all */
    $all = Artisan::all();

    return $all;
}

/**
 * Class-name prefixes of the commands that rewrite or empty the schema. Named
 * as prefixes rather than one by one, so a framework upgrade that adds another
 * command to the migration namespace is refused on arrival.
 *
 * @return list<string>
 */
function devConsoleSchemaDestructiveClasses(): array
{
    return [
        'Illuminate\\Database\\Console\\Migrations\\',
        'Illuminate\\Database\\Console\\WipeCommand',
        'Illuminate\\Database\\Console\\Seeds\\SeedCommand',
    ];
}

/**
 * The second net, for a first-party command the namespace test cannot see.
 */
function devConsoleDropsTheSchema(string $source): bool
{
    return PatternScan::matches('/dropAllTables|dropAllViews|db:wipe|migrate:fresh|DROP\s+TABLE/i', $source);
}

/**
 * @return array<string, string> class => why a prompt inside it is unreachable
 *                               from a console run
 */
function devConsolePromptingCommandsThatStillRun(): array
{
    return [
        'Modules\\Core\\Internal\\Console\\InstallCommand' => 'prompts only on the branch that CREATES the first account, and a console run is authenticated, so a user already exists and that branch returns first',
        'Modules\\Core\\Internal\\Console\\RestoreDatabaseCommand' => 'prompts only when --confirm is absent, and the registry supplies it as a fixed flag',
    ];
}

function devConsoleAsksAQuestion(string $source): bool
{
    return PatternScan::matches('/\$this->(secret|ask|askWithCompletion|anticipate|choice|confirm)\s*\(/', $source);
}

function devConsoleRefusesWithoutATerminal(string $source): bool
{
    return PatternScan::matches('/input->isInteractive\s*\(\s*\)/', $source);
}

function devConsoleSourceOf(ConsoleCommand $command): string
{
    $file = (new ReflectionClass($command))->getFileName();

    if ($file === false || ! is_file($file)) {
        throw new RuntimeException('No source file for '.$command::class);
    }

    $source = file_get_contents($file);

    if ($source === false || $source === '') {
        throw new RuntimeException('Unreadable source for '.$command::class.' at '.$file);
    }

    return $source;
}

it('resolves every registered name against the live console application', function (): void {
    $specs = devConsoleRegisteredSpecs();
    $live = devConsoleLiveCommands();

    expect($specs)->not->toBe([]);
    expect($live)->not->toBe([]);

    $unresolved = [];
    foreach ($specs as $spec) {
        if (! array_key_exists($spec->name, $live)) {
            $unresolved[] = $spec->name;
        }
    }

    expect($unresolved)->toBe([], implode("\n  ", array_merge(
        ['The allow-list names a command artisan does not answer to, so every check below',
            'read nothing for it and passed by reading nothing. Offenders:'],
        $unresolved,
    )));
});

it('registers no command that drops or reseeds the schema', function (): void {
    $live = devConsoleLiveCommands();
    $offenders = [];

    foreach (devConsoleRegisteredSpecs() as $spec) {
        $command = $live[$spec->name] ?? null;
        if (! $command instanceof ConsoleCommand) {
            continue;
        }

        $class = $command::class;

        foreach (devConsoleSchemaDestructiveClasses() as $prefix) {
            if (str_starts_with($class, $prefix)) {
                $offenders[] = $spec->name.'  ('.$class.')';

                continue 2;
            }
        }

        if (devConsoleDropsTheSchema(devConsoleSourceOf($command))) {
            $offenders[] = $spec->name.'  ('.$class.' drops or wipes the schema)';
        }
    }

    expect($offenders)->toBe([], implode("\n  ", array_merge(
        ['A schema-destructive command is in the Dev Console allow-list. The console is reachable',
            'by any account carrying the developer flag, and on a checkout or a self-hosted install',
            'APP_ENV=local means Laravel asks for no confirmation of its own — so this is one modal',
            'away from dropping the household ledger. It belongs outside the registry, not behind a',
            'gate. This checks the REGISTERED command, not what it calls. Offenders:'],
        $offenders,
    )));
});

it('names only arguments and options the command declares, and every argument it requires', function (): void {
    $live = devConsoleLiveCommands();
    $offenders = [];
    $inspected = 0;

    foreach (devConsoleRegisteredSpecs() as $spec) {
        $command = $live[$spec->name] ?? null;
        if (! $command instanceof ConsoleCommand) {
            continue;
        }

        $definition = $command->getDefinition();

        // Positionals only. An option named `--queue` must not be read as
        // satisfying a required argument that happens to be called `queue`,
        // which is the mis-match this test exists to catch.
        $positionals = [];

        foreach ($spec->argsSchema as $arg) {
            $inspected++;

            if (str_starts_with($arg->name, '--')) {
                if (! $definition->hasOption(substr($arg->name, 2))) {
                    $offenders[] = $spec->name.'  declares option '.$arg->name.', which the command does not';
                }

                continue;
            }

            $positionals[] = $arg->name;

            if (! $definition->hasArgument($arg->name)) {
                $offenders[] = $spec->name.'  declares argument `'.$arg->name.'`, which the command does not — artisan answers "Too many arguments"';
            }
        }

        foreach ($spec->fixedFlags as $flag) {
            $inspected++;
            if (! str_starts_with($flag, '--') || ! $definition->hasOption(substr($flag, 2))) {
                $offenders[] = $spec->name.'  always passes '.$flag.', which the command does not declare';
            }
        }

        foreach ($definition->getArguments() as $argument) {
            if ($argument->isRequired() && ! in_array($argument->getName(), $positionals, true)) {
                $offenders[] = $spec->name.'  requires argument `'.$argument->getName().'`, which the schema never supplies';
            }
        }
    }

    expect($inspected)->toBeGreaterThan(0);
    expect($offenders)->toBe([], implode("\n  ", array_merge(
        ['The runner builds its command line from argsSchema and fixedFlags alone, so a name the',
            'command does not declare is a run that always exits non-zero — and an unreachable',
            'command is not a safe one, it is a broken one somebody will later "fix" without',
            'knowing what it was gated for. Offenders:'],
        $offenders,
    )));
});

it('registers no command that refuses to run without a terminal', function (): void {
    $live = devConsoleLiveCommands();
    $exempt = devConsolePromptingCommandsThatStillRun();
    $offenders = [];
    $read = 0;

    foreach (devConsoleRegisteredSpecs() as $spec) {
        $command = $live[$spec->name] ?? null;
        if (! $command instanceof ConsoleCommand) {
            continue;
        }

        $source = devConsoleSourceOf($command);
        $read++;

        if (devConsoleRefusesWithoutATerminal($source)) {
            $offenders[] = $spec->name.'  refuses a non-interactive run outright';

            continue;
        }

        if (devConsoleAsksAQuestion($source) && ! array_key_exists($command::class, $exempt)) {
            $offenders[] = $spec->name.'  asks a question the detached child cannot answer';
        }
    }

    expect($read)->toBe(count(devConsoleRegisteredSpecs()));
    expect($offenders)->toBe([], implode("\n  ", array_merge(
        ['The spawner redirects the child\'s stdin from /dev/null, so a prompt reads EOF and the',
            'run dies on Symfony\'s "Aborted." Either give the command a flag the registry can pass',
            'as a fixed flag, or leave it out — a command that can only be run from a terminal is',
            'gated ON having a terminal, which is the gate the console removes. Offenders:'],
        $offenders,
    )));
});

it('exempts no prompting command the walk does not reach', function (): void {
    $live = devConsoleLiveCommands();
    $reached = [];

    foreach (devConsoleRegisteredSpecs() as $spec) {
        $command = $live[$spec->name] ?? null;
        if ($command instanceof ConsoleCommand && devConsoleAsksAQuestion(devConsoleSourceOf($command))) {
            $reached[] = $command::class;
        }
    }

    $stale = array_values(array_diff(array_keys(devConsolePromptingCommandsThatStillRun()), $reached));

    expect($stale)->toBe([], implode("\n  ", array_merge(
        ['An exemption claims a prompting command is registered and safe, but the walk never',
            'produced it — so the exemption is proving nothing and hiding whatever replaces it.',
            'Delete it, or fix the walk that stopped seeing it. Stale entries:'],
        $stale,
    )));
});

it('can see each shape it exists to refuse', function (): void {
    expect(devConsoleDropsTheSchema('$this->call(\'db:wipe\', []);'))->toBeTrue();
    expect(devConsoleDropsTheSchema('$schema->dropAllTables();'))->toBeTrue();
    expect(devConsoleDropsTheSchema('DB::statement("DROP TABLE transactions");'))->toBeTrue();
    expect(devConsoleDropsTheSchema('$this->call(\'migrate\', [\'--force\' => true]);'))->toBeFalse();

    expect(devConsoleRefusesWithoutATerminal('if (! $this->input->isInteractive()) {'))->toBeTrue();
    expect(devConsoleRefusesWithoutATerminal('$this->info(\'interactive\');'))->toBeFalse();

    expect(devConsoleAsksAQuestion('$password = $this->secret(\'New password\');'))->toBeTrue();
    expect(devConsoleAsksAQuestion('$ok = $this->confirm(\'Sure?\', false);'))->toBeTrue();
    expect(devConsoleAsksAQuestion('$this->line(\'no question here\');'))->toBeFalse();
});

it('supplies db:restore with both flags its own handler demands', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);

    $spec = $registry->find('db:restore');

    expect($spec->fixedFlags)->toBe(['--confirm', '--force-maintenance']);

    $names = array_map(static fn (ArgSpec $arg): string => $arg->name, $spec->argsSchema);
    expect($names)->toBe(['path']);
});
