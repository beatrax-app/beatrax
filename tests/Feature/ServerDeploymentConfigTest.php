<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Public\Support\PatternScan;

// This file used to assert the opposite — that `pgsql`, `mysql` and `mariadb`
// were defined "so DB_CONNECTION can select them". They were, and selecting one
// took an operator as far as the first substantive migration, where a
// RAISE(ABORT) enum-guard trigger is not PostgreSQL syntax. ADR-0022 withdrew
// the options; these assertions hold them withdrawn.

// Prose is not the target: the deployment guide and the compose header both
// name PostgreSQL to say it does not work, which is the opposite of offering
// it. What is checked here is what a machine reads — a config value, a dotenv
// key, a string literal the command hands the operator, an installed driver.

// Keys that only exist to reach a database over a network.
const SERVER_ONLY_DB_KEYS = [
    'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD',
    'DB_SOCKET', 'DB_CHARSET', 'DB_COLLATION', 'DB_SEARCH_PATH', 'DB_SSLMODE',
];

const WITHDRAWN_ENGINES = ['pgsql', 'mysql', 'mariadb'];

it('configures no database engine other than SQLite', function (): void {
    /** @var Repository $config */
    $config = app(Repository::class);

    /** @var array<string, array<string, mixed>> $connections */
    $connections = $config->get('database.connections');

    $engines = [];
    foreach ($connections as $name => $connection) {
        $driver = $connection['driver'] ?? null;
        $engines[$name] = is_string($driver) ? $driver : '(none)';
    }

    expect(array_values(array_unique(array_values($engines))))->toBe(
        ['sqlite'],
        "Thirty-two migrations use SQLite-only RAISE(ABORT) enum-guard triggers and\n".
        "search is an FTS5 virtual table, so `migrate` against a server database\n".
        "fails on the first substantive table. A connection that cannot be migrated\n".
        'is not an option, however selectable it looks. Found: '.
        json_encode($engines, JSON_THROW_ON_ERROR),
    );
});

it('keeps sqlite the default connection so the desktop build is unaffected', function (): void {
    /** @var Repository $config */
    $config = app(Repository::class);

    expect($config->get('database.default'))->toBe('sqlite_testing'); // testing env override
    expect($config->get('database.connections.sqlite.driver'))->toBe('sqlite');
});

it('offers no server database in any .env an operator copies', function (): void {
    $offenders = [];

    foreach (['.env.example', 'deploy/server/.env.example'] as $file) {
        $source = (string) file_get_contents(base_path($file));

        // Commented lines are read too: the residue this replaces was a block
        // of `# DB_HOST=…` under an instruction to uncomment it.
        foreach (PatternScan::sets('/^#?\s*(DB_[A-Z_]+)\s*=\s*(.*)$/m', $source) as $line) {
            [, $key, $value] = $line;

            if (in_array($key, SERVER_ONLY_DB_KEYS, true)) {
                $offenders[] = $file.' offers '.$key;
            }

            if ($key === 'DB_CONNECTION' && trim($value) !== 'sqlite') {
                $offenders[] = $file.' offers DB_CONNECTION='.trim($value);
            }
        }
    }

    expect($offenders)->toBe(
        [],
        "An operator configures from these files. A key that only means something\n".
        "to a server database is an offer the product cannot honour, whether or\n".
        "not it is commented out. Offenders:\n  ".implode("\n  ", $offenders),
    );
});

it('names no withdrawn engine in the command that writes an operator\'s .env', function (): void {
    $source = (string) file_get_contents(base_path('app/Console/Commands/SetupCommand.php'));
    $offenders = [];

    // String literals only. A comment explaining why the option is gone is
    // exactly what should be allowed to survive here.
    foreach (token_get_all($source) as $token) {
        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        if (in_array(strtolower(substr($token[1], 1, -1)), WITHDRAWN_ENGINES, true)) {
            $offenders[] = 'line '.$token[2].': '.$token[1];
        }
    }

    expect($offenders)->toBe(
        [],
        "`beatrax:setup` used to present PostgreSQL as \"recommended for a server\",\n".
        "write DB_CONNECTION=pgsql, report the connection OK, and hand the operator\n".
        "to `beatrax:install`, which failed on the first table. Offenders:\n  ".
        implode("\n  ", $offenders),
    );
});

it('installs no PDO driver in the server image beyond SQLite', function (): void {
    $dockerfile = (string) file_get_contents(base_path('deploy/server/Dockerfile'));

    $drivers = PatternScan::all('/\bpdo_[a-z]+\b/', $dockerfile)[0];

    expect(array_values(array_unique($drivers)))->toBe(
        ['pdo_sqlite'],
        'The shipped image carried pdo_pgsql and pdo_mysql, which is a runtime '.
        'extension bundled for a deployment shape the schema refuses. Found: '.
        implode(', ', $drivers),
    );
});

it('registers the interactive beatrax:setup command', function (): void {
    expect(Artisan::all())->toHaveKey('beatrax:setup');
});
