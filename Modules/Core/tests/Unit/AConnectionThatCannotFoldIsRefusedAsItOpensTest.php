<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\SQLiteConnection;
use Modules\Core\Internal\Exceptions\UnicodeFoldingUnavailableException;
use Modules\Core\Internal\Providers\UnicodeFoldingProvider;
use Modules\Core\Public\Support\UnicodeFolding;
use Modules\Core\Tests\Support\RefusingSqlitePdo;
use Pdo\Sqlite;

// The two ways registration can fail and the one way it must decline instead,
// each reached with a real PDO rather than a mock. The first is what `new PDO`
// hands back: how Laravel builds its connection below PHP 8.4, and how anything
// opening the file outside the framework does at any version.

function cannotFoldConnection(PDO $pdo, string $name, string $driver = 'sqlite'): Connection
{
    return new SQLiteConnection($pdo, 'main', '', ['driver' => $driver, 'name' => $name]);
}

it('refuses a connection whose PDO cannot carry a user function, naming it', function (): void {
    $connection = cannotFoldConnection(new PDO('sqlite::memory:'), 'pdo-without-the-subclass');

    expect(fn () => UnicodeFolding::registerOn($connection))
        ->toThrow(UnicodeFoldingUnavailableException::class);

    try {
        UnicodeFolding::registerOn($connection);
    } catch (UnicodeFoldingUnavailableException $e) {
        expect($e->connectionName)->toBe('pdo-without-the-subclass')
            ->and($e->getMessage())->toContain('pdo-without-the-subclass')
            ->and($e->getMessage())->toContain(UnicodeFolding::SQL_FUNCTION);
    }
});

it('refuses a connection whose driver declines the registration, naming it', function (): void {
    $connection = cannotFoldConnection(new RefusingSqlitePdo('sqlite::memory:'), 'driver-said-no');

    try {
        UnicodeFolding::registerOn($connection);
        $this->fail('A driver that refused the registration was treated as having accepted it.');
    } catch (UnicodeFoldingUnavailableException $e) {
        expect($e->connectionName)->toBe('driver-said-no')
            ->and($e->getMessage())->toContain('driver-said-no')
            ->and($e->getMessage())->toContain(UnicodeFolding::SQL_FUNCTION);
    }
});

// The PDO here is the one the first case proves throws, so a green line is the
// driver check declining and not the registration quietly succeeding.
it('leaves a connection that is not SQLite alone', function (): void {
    $connection = cannotFoldConnection(new PDO('sqlite::memory:'), 'not-sqlite', driver: 'mysql');

    (new UnicodeFoldingProvider($this->app))->boot($this->app->make('events'), $this->app->make('db'));

    $this->app->make('events')->dispatch(new ConnectionEstablished($connection));
})->throwsNoExceptions();

it('folds case over the whole of Unicode and leaves diacritics standing', function (): void {
    expect(UnicodeFolding::of('MÖRK ΑΘΗΝΑ ЩУКА'))->toBe('mörk αθηνα щука')
        // Case only. Stripping the accent as well would make `o` find `ö`,
        // which is not what the trigram tokenizer the other arm uses does.
        ->and(UnicodeFolding::of('Ö'))->not->toBe('o')
        ->and(UnicodeFolding::sql('search_body'))->toBe(UnicodeFolding::SQL_FUNCTION.'(search_body)');
});

// SQLite hands the column's own affinity to the callback, so the registered
// function meets values that are not strings. A NULL folding to anything but
// NULL would make a row with no note match a search for the empty string.
it('answers a null argument with null and a numeric one with its digits', function (): void {
    $pdo = new Sqlite('sqlite::memory:');
    $connection = cannotFoldConnection($pdo, 'affinity-probe');

    UnicodeFolding::registerOn($connection);

    $folded = $connection->selectOne(
        'select '.UnicodeFolding::sql('?').' as folded_null, '.UnicodeFolding::sql('?').' as folded_number',
        [null, 1234],
    );

    expect($folded)->toBeInstanceOf(stdClass::class);
    expect($folded->folded_null)->toBeNull()
        ->and((string) $folded->folded_number)->toBe('1234');
});

it('registers a function that is deterministic, so SQLite may reuse its answer', function (): void {
    $pdo = new Sqlite('sqlite::memory:');
    expect($pdo)->toBeInstanceOf(Sqlite::class);

    UnicodeFolding::registerOn(cannotFoldConnection($pdo, 'determinism-probe'));

    // A non-deterministic function is refused in an index expression outright,
    // so building one is the assertion.
    $pdo->exec('create table notes(body text not null)');
    $pdo->exec('create index notes_folded on notes('.UnicodeFolding::sql('body').')');

    expect($pdo->query("select count(*) c from sqlite_master where name = 'notes_folded'")->fetchColumn())
        ->toEqual(1);
});
