<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// The reportable that withholds them was registered on the desktop root alone,
// so the phone — the bundle whose log file sits in a world-readable app
// directory — logged every failed statement with its bindings in full. This
// runs from both roots, and the file each one loads is the one under test.

function queryBindingsRecorder(): LoggerInterface
{
    return new class extends AbstractLogger
    {
        /** @var list<array{message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = ['message' => (string) $message, 'context' => $context];
        }
    };
}

it('names the failure and never the row it failed on', function (): void {
    $logger = queryBindingsRecorder();
    $this->app->instance(LoggerInterface::class, $logger);
    $this->app->instance('log', $logger);

    $this->app->make(ExceptionHandler::class)->report(new QueryException(
        'sqlite',
        'insert into "transactions" ("counterparty_name", "amount_minor") values (?, ?)',
        ['Dr A. Specialist', -145_00],
        new PDOException('SQLSTATE[HY000]: General error: 1 no such table: transactions'),
    ));

    $written = array_values(array_filter(
        $logger->records,
        static fn (array $record): bool => $record['message'] === 'Database query failed.',
    ));

    expect($written)->toHaveCount(
        1,
        'The root that loaded this bootstrap file registers no QueryException reportable, so the framework logged the exception message — statement and bindings — instead.',
    );

    $encoded = json_encode($written[0], JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('Dr A. Specialist')
        ->and($encoded)->not->toContain('14500')
        ->and($encoded)->not->toContain('insert into');
});
