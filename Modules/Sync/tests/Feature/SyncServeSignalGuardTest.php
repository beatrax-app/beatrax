<?php

declare(strict_types=1);

/*
 * The desktop app runs `sync:serve` as a persistent NativePHP ChildProcess,
 * using the PHP binary NativePHP bundles — which ships WITHOUT ext-pcntl.
 * An unguarded `\Amp\trapSignal([\SIGTERM, \SIGINT])` is then an undefined
 * constant, so the command fatals the instant after it has bound the port and
 * the supervisor restarts it, forever. The symptom is the worst kind: a
 * listener process visible in `ps`, a port that never answers, and a console
 * repeating "Trying to access array offset on null" from the restart path.
 *
 * These are source assertions rather than a run, because the failure only
 * reproduces on a runtime this suite cannot create: the test process has
 * ext-pcntl, so calling the command here takes the trapping branch and proves
 * nothing about the branch that broke.
 */

$source = static fn (): string => (string) file_get_contents(
    base_path('Modules/Sync/Commands/SyncServeCommand.php'),
);

// trapSignal() itself now lives in the shared wait both daemons park on, so
// the guard invariant is asserted against that file rather than the command.
$waitSource = static fn (): string => (string) file_get_contents(
    base_path('Modules/Sync/Internal/Transport/DaemonShutdownSignal.php'),
);

it('never reaches trapSignal without checking the runtime can trap', function () use ($source, $waitSource): void {
    // The command decides, and passes the answer in.
    expect($source())->toContain('$this->shutdown->await($this->canTrapSignals())');

    $contents = $waitSource();

    // And the wait only traps when told it may.
    $guardPos = mb_strpos($contents, 'if ($trapSignals) {');
    $trapPos = mb_strpos($contents, 'Amp\trapSignal');

    expect($guardPos)->not->toBeFalse();
    expect($trapPos)->not->toBeFalse();
    expect($trapPos)->toBeGreaterThan($guardPos);
});

it('requires both the function and the constants before trapping', function () use ($source): void {
    // A runtime can expose one without the other, and either alone still
    // fatals — pcntl_signal without SIGTERM defined is exactly the shape the
    // bundled binary presents.
    expect($source())
        ->toContain("function_exists('pcntl_signal')")
        ->toContain("defined('SIGTERM')")
        ->toContain("defined('SIGINT')");
});

it('parks instead of returning when it cannot trap', function () use ($waitSource): void {
    // Falling through would stop the HTTP server and exit cleanly, which the
    // supervisor reads as "finished" and restarts — the same crash loop by a
    // quieter route. The listener has to stay up until something ends it.
    expect($waitSource())->toContain('DeferredFuture');
});

it('ends the wait when the host that spawned it goes away', function () use ($waitSource): void {
    // A persistent ChildProcess outlives Electron on purpose, and a FORCE quit
    // never runs the supervisor's before-quit hook — so the closing stdin pipe
    // is the only notice the daemon gets that it is now an orphan holding a
    // port nobody can use.
    expect($waitSource())
        ->toContain('getStdin()')
        ->toContain('parent pipe closed');
});

it('does not promise signal handling it may not have', function () use ($source): void {
    // The startup line is the only feedback anyone gets from a daemon; saying
    // "SIGTERM/SIGINT to stop" on a runtime that cannot trap either is a lie
    // that sends the next person looking in the wrong place.
    expect($source())->toContain('no signal handling on this runtime');
});
