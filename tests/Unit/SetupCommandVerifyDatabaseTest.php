<?php

declare(strict_types=1);

use App\Console\Commands\SetupCommand;
use App\Setup\DatabaseProbe;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// verifyDatabase() is private on an interactive command, so it is reached by
// reflection with the output components wired to a buffer — what the operator
// would have seen is the thing being asserted on.

/**
 * @param  array<string, string>  $env
 */
function runVerifyDatabase(string $driver, array $env, DatabaseProbe $probe): string
{
    $command = new SetupCommand;

    $output = new BufferedOutput;
    $style = new OutputStyle(new ArrayInput([]), $output);

    $components = new ReflectionProperty(Command::class, 'components');
    $components->setValue($command, new Factory($style));

    $method = new ReflectionMethod(SetupCommand::class, 'verifyDatabase');
    $method->invoke($command, $driver, $env, $probe);

    return $output->fetch();
}

it('names the server it reached so a wrong instance is visible', function (): void {
    $probe = new class extends DatabaseProbe
    {
        public function serverVersion(string $dsn, string $username, string $password): string
        {
            return '8.0.36';
        }
    };

    $rendered = runVerifyDatabase('mysql', ['DB_HOST' => 'db.internal', 'DB_DATABASE' => 'beatrax'], $probe);

    expect($rendered)->toContain('Database connection OK')
        ->and($rendered)->toContain('mysql')
        ->and($rendered)->toContain('8.0.36');
});

it('falls back to a bare confirmation when the server reports no version', function (): void {
    $probe = new class extends DatabaseProbe
    {
        public function serverVersion(string $dsn, string $username, string $password): string
        {
            return '';
        }
    };

    $rendered = runVerifyDatabase('pgsql', ['DB_HOST' => '127.0.0.1'], $probe);

    expect($rendered)->toContain('Database connection OK')
        ->and($rendered)->not->toContain('(');
});

it('warns rather than failing when the server is not up yet', function (): void {
    $probe = new class extends DatabaseProbe
    {
        public function serverVersion(string $dsn, string $username, string $password): string
        {
            throw new PDOException('could not find driver');
        }
    };

    // A fresh deploy legitimately has no database yet, so this path must
    // stay a warning the operator can act on rather than a hard stop.
    $rendered = runVerifyDatabase('mysql', ['DB_HOST' => 'nope'], $probe);

    expect($rendered)->toContain('Could not connect yet')
        ->and($rendered)->toContain('could not find driver');
});
