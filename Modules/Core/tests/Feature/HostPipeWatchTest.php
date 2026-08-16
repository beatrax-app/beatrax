<?php

declare(strict_types=1);

use Modules\Core\Public\Services\HostPipeWatch;

/*
 * How a child process learns its host is gone.
 *
 * The desktop shell spawns daemons and queue workers as PERSISTENT child
 * processes, on purpose, so a crash-restart does not drop them. On an orderly
 * quit the supervisor stops them. A force quit kills it outright, that hook
 * never runs, and they keep going — a queue listener was found still alive
 * three hours after the app died, spawning a job process every few seconds
 * against an app that no longer existed.
 *
 * Neither ext-pcntl nor ext-posix ships in the bundled PHP, so there is no
 * signal to trap and no getppid() to poll. The stdin pipe the host holds is
 * what is left.
 *
 * The false-positive direction matters as much as the true one: reading a
 * terminal, or /dev/null, as "the host has gone" would make a hand-run
 * `php artisan queue:work` exit the moment it started.
 */

// Runs a one-liner in a child whose stdin we control, and reports what the
// watch concluded there.
function hostPipeProbe(string $expression, bool $closeStdin): string
{
    $script = '<?php require '.var_export(base_path('vendor/autoload.php'), true).';'
        .'$app = require '.var_export(base_path('bootstrap/app.php'), true).';'
        .'$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
        .'usleep(200000);'
        .'echo '.$expression.' ? "YES" : "NO";';

    $path = tempnam(sys_get_temp_dir(), 'beatrax-pipe-').'.php';
    file_put_contents($path, $script);

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open([PHP_BINARY, $path], $descriptors, $pipes, base_path());

    if (! is_resource($process)) {
        unlink($path);
        test()->markTestSkipped('could not spawn a child process here');
    }

    if ($closeStdin) {
        fclose($pipes[0]);
    }

    $out = (string) stream_get_contents($pipes[1]);

    if (! $closeStdin) {
        fclose($pipes[0]);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    unlink($path);

    return $out;
}

it('sees a pipe held by a host', function (): void {
    expect(hostPipeProbe('Modules\Core\Public\Services\HostPipeWatch::isHeldByHost()', closeStdin: false))
        ->toContain('YES');
});

it('reports the host gone once that pipe closes', function (): void {
    expect(hostPipeProbe('Modules\Core\Public\Services\HostPipeWatch::hostHasGone()', closeStdin: true))
        ->toContain('YES');
});

it('does not report a live host as gone', function (): void {
    expect(hostPipeProbe('Modules\Core\Public\Services\HostPipeWatch::hostHasGone()', closeStdin: false))
        ->toContain('NO');
});

// The suite itself runs without a host pipe, which is the same shape as a
// hand-run artisan command: the watch must stay silent there.
it('never claims a host has gone when there was none', function (): void {
    if (HostPipeWatch::isHeldByHost()) {
        test()->markTestSkipped('this runner was given a stdin pipe');
    }

    expect(HostPipeWatch::hostHasGone())->toBeFalse();
});
