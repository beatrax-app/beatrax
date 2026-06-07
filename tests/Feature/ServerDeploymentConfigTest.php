<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Artisan;

it('defines the server database connections so DB_CONNECTION can select them', function (string $name, string $driver): void {
    /** @var Repository $config */
    $config = app(Repository::class);

    expect($config->get("database.connections.$name.driver"))->toBe($driver);
})->with([
    'postgres' => ['pgsql', 'pgsql'],
    'mysql' => ['mysql', 'mysql'],
    'mariadb' => ['mariadb', 'mariadb'],
]);

it('keeps sqlite as the default connection (desktop build unaffected)', function (): void {
    /** @var Repository $config */
    $config = app(Repository::class);

    expect($config->get('database.default'))->toBe('sqlite_testing'); // testing env override
    expect($config->get('database.connections.sqlite.driver'))->toBe('sqlite');
});

it('registers the interactive beatrax:setup command', function (): void {
    expect(Artisan::all())->toHaveKey('beatrax:setup');
});
