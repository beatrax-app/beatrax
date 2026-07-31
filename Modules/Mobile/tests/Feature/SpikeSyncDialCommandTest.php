<?php

declare(strict_types=1);

use Amp\CancelledException;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Modules\Mobile\Internal\Spike\SpikeSyncDialCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * SpikeSyncDialCommandTest — the mobile:spike-dial loop-topology spike.
 *
 * The command's whole point is to prove the amphp/Revolt loop drives a
 * single bounded dial-out to completion. Its outcome-splitting helper,
 * reportDialFailure(), is what the on-device NO-GO signal keys off:
 *
 *   - an unreachable peer / fired connect budget (WebsocketConnectException
 *     or CancelledException) still proves the loop ran  -> SUCCESS
 *   - any OTHER throwable is the native-loop / Revolt-driver conflict the
 *     on-device run watches for                          -> FAILURE
 *
 * A live successful dial (a real desktop sync:serve on the other end) is
 * Manual-Only Verification — the same documented precedent every other
 * Mobile LAN test follows — so the two catch outcomes are driven directly
 * through the private helper here, and the connect-refused SUCCESS path is
 * additionally exercised end-to-end through handle() against a dead port.
 */

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

    // 127.0.0.1 with nothing listening -> connection refused -> the dial
    // throws WebsocketConnectException, which handle() routes through
    // reportDialFailure() to a SUCCESS (the Revolt loop still ran to a
    // definite result). Port chosen high/unregistered to stay refused-fast.
    $this->artisan('mobile:spike-dial', ['--host' => '127.0.0.1', '--port' => '59991'])
        ->expectsOutputToContain('RESULT: SUCCESS')
        ->assertExitCode(SpikeSyncDialCommand::SUCCESS);
})->group('touches-network');
