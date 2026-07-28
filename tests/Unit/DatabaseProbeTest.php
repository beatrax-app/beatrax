<?php

declare(strict_types=1);

use App\Setup\DatabaseProbe;

/*
 * The setup command's reachability check. Exercised against SQLite rather
 * than the MySQL and Postgres DSNs it is built for, because the behaviour
 * under test is the same either way: open a connection, report which server
 * answered, and let a failure surface as PDOException for the command to
 * turn into a warning.
 */

it('reports the version of the server that answered', function (): void {
    $version = (new DatabaseProbe)->serverVersion('sqlite::memory:', '', '');

    // A real version string, not a placeholder — the point of asking is to
    // tell the operator which instance they actually reached.
    expect($version)->not->toBe('')
        ->and($version)->toMatch('~^\d+\.\d+~');
});

it('raises rather than reporting a false success when the server is absent', function (): void {
    $probe = new DatabaseProbe;

    expect(fn (): string => $probe->serverVersion('sqlite:/nonexistent-directory/beatrax.sqlite', '', ''))
        ->toThrow(PDOException::class);
});

it('raises on a driver that is not installed', function (): void {
    $probe = new DatabaseProbe;

    expect(fn (): string => $probe->serverVersion('nosuchdriver:host=127.0.0.1', 'u', 'p'))
        ->toThrow(PDOException::class);
});
