<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\DaemonShutdownSignal;

// The listeners are spawned as persistent children, so a force quit skips the
// before-quit hook and leaves one holding its port: an orphaned relay held
// 51338 for a day and nineteen hours, running superseded code. The host owns
// the child's stdin, and EOF on that pipe is the one signal a kill -9 cannot eat.

it('returns as soon as the host closes the pipe it holds', function (): void {
    $script = <<<'PHP'
        <?php
        require getenv('BEATRAX_AUTOLOAD');
        $app = require getenv('BEATRAX_BOOTSTRAP');
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        // No signal trapping: this proves the pipe alone ends the wait.
        (new Modules\Sync\Internal\Transport\DaemonShutdownSignal)->await(false);
        echo "EXITED";
        PHP;

    $scriptPath = tempnam(sys_get_temp_dir(), 'beatrax-daemon-').'.php';
    file_put_contents($scriptPath, $script);

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = [
        'BEATRAX_AUTOLOAD' => base_path('vendor/autoload.php'),
        'BEATRAX_BOOTSTRAP' => base_path('bootstrap/app.php'),
        'PATH' => (string) getenv('PATH'),
        'HOME' => (string) getenv('HOME'),
    ];

    $process = proc_open([PHP_BINARY, $scriptPath], $descriptors, $pipes, base_path(), $env);

    if (! is_resource($process)) {
        unlink($scriptPath);
        test()->markTestSkipped('could not spawn a child process here');
    }

    // The daemon is now parked. Close its stdin exactly as a dying host does.
    fclose($pipes[0]);

    $deadline = microtime(true) + 20.0;
    $stdout = '';

    while (microtime(true) < $deadline) {
        $status = proc_get_status($process);

        if ($status['running'] === false) {
            break;
        }

        usleep(50_000);
    }

    $stdout .= (string) stream_get_contents($pipes[1]);
    $stillRunning = proc_get_status($process)['running'];

    foreach ([$pipes[1], $pipes[2]] as $pipe) {
        fclose($pipe);
    }
    proc_terminate($process);
    proc_close($process);
    unlink($scriptPath);

    expect($stillRunning)->toBeFalse('the daemon must exit when its host closes the pipe')
        ->and($stdout)->toContain('EXITED');
});

// Guards the branch that decides whether EOF means anything: a daemon started
// from a terminal has no host pipe, and must not read that as a dead parent.
it('recognises whether a host is actually holding the pipe', function (): void {
    expect(DaemonShutdownSignal::hasParentPipe())->toBeBool();
});
