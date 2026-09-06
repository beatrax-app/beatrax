<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Modules\Desktop\Internal\Listeners\SurfaceWorkerCrashAlert;
use Native\Desktop\Contracts\ChildProcess as ChildProcessContract;
use Native\Desktop\QueueWorker;

// The watchdog compared the arriving alias against a constant of its own, and
// every case that had ever exercised it built the event out of that same
// constant. The two agreed on `queue-default` for as long as the file existed;
// the shell sends `queue_default`, so the comparison was false on every exit
// and no crash storm could raise an alert. Confirmed on desktop hardware on
// 2026-09-06 by killing the supervised worker three times inside the window:
// three exits were recorded and no alert was written.
//
// This case is the one that could have caught it, because the alias comes out
// of the vendor's own construction rather than out of ours.
it('recognises the alias the shell actually spells', function (): void {
    $captured = [];

    $childProcess = Mockery::mock(ChildProcessContract::class);
    $childProcess->shouldReceive('artisan')
        ->once()
        ->andReturnUsing(function (array $command, string $alias, mixed ...$rest) use (&$captured, &$childProcess): ChildProcessContract {
            $captured[] = $alias;

            return $childProcess;
        });

    (new QueueWorker($childProcess))->up('default');

    expect($captured)->toHaveCount(1, 'QueueWorker::up no longer starts one child per worker, so this reads nothing.');

    /** @var SurfaceWorkerCrashAlert $listener */
    $listener = app(SurfaceWorkerCrashAlert::class);

    expect($listener->isSupervisedWorker($captured[0]))->toBeTrue(
        'The shell starts the worker as `'.$captured[0].'` and the watchdog does not recognise it, so every '
        .'exit is recorded and none is ever counted.',
    );
});

// The other direction: the derivation has to follow the configuration rather
// than a name written once, or a worker added there is unwatched in silence.
it('watches every worker the bundle is configured to supervise', function (): void {
    /** @var ConfigRepository $config */
    $config = app(ConfigRepository::class);
    $config->set('nativephp.queue_workers', ['default' => [], 'reports' => []]);

    /** @var SurfaceWorkerCrashAlert $listener */
    $listener = app(SurfaceWorkerCrashAlert::class);

    expect($listener->supervisedAliases())->toBe(['queue_default', 'queue_reports'])
        ->and($listener->isSupervisedWorker('queue_reports'))->toBeTrue()
        ->and($listener->isSupervisedWorker('sync-listener'))->toBeFalse();
});
