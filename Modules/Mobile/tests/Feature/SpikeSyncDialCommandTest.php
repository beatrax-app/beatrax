<?php

declare(strict_types=1);

use Amp\CancelledException;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Modules\Mobile\Internal\Spike\SpikeSyncDialCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function invokeReportDialFailure(Throwable $e): int
{
    $command = new SpikeSyncDialCommand;

    // reportDialFailure() writes through $this->warn/line/error, so the
    // command needs an output wired before it can be driven in isolation.
    $output = new OutputStyle(new ArrayInput([]), new BufferedOutput);
    (new ReflectionProperty($command, 'output'))->setValue($command, $output);

    $method = new ReflectionMethod($command, 'reportDialFailure');

    return $method->invoke($command, $e);
}

// A live successful dial needs a real desktop sync:serve on the other end, so it
// stays manual verification. The two catch outcomes are driven through the private
// helper instead, and the connect-refused path additionally runs through handle()
// against a dead port.

it('reports SUCCESS for a CancelledException — a fired connect budget still proves the loop drove to completion', function (): void {
    expect(invokeReportDialFailure(new CancelledException))->toBe(SpikeSyncDialCommand::SUCCESS);
});

it('reports FAILURE for any other throwable — the native-loop / Revolt-driver conflict NO-GO signal', function (): void {
    expect(invokeReportDialFailure(new RuntimeException('driver conflict')))->toBe(SpikeSyncDialCommand::FAILURE);
});

it('rejects an out-of-range port before dialing', function (): void {
    Artisan::registerCommand(new SpikeSyncDialCommand);

    $this->artisan('mobile:spike-dial', ['--port' => '0'])
        ->expectsOutputToContain('invalid port')
        ->assertExitCode(SpikeSyncDialCommand::FAILURE);
});

it('drives handle() to a clean SUCCESS when the peer is unreachable (connect refused)', function (): void {
    Artisan::registerCommand(new SpikeSyncDialCommand);

    // Nothing listening means connection refused, so the dial throws and handle()
    // routes it to SUCCESS: the Revolt loop still ran to a definite result. The
    // port is high and unregistered to stay refused-fast.
    $this->artisan('mobile:spike-dial', ['--host' => '127.0.0.1', '--port' => '59991'])
        ->expectsOutputToContain('RESULT: SUCCESS')
        ->assertExitCode(SpikeSyncDialCommand::SUCCESS);
})->group('touches-network');
