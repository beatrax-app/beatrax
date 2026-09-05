<?php

declare(strict_types=1);

// The shell spawns daemons and queue workers as persistent children on purpose,
// so a force quit skips the supervisor's stop hook and leaves them running — one
// queue listener was found alive three hours after the app died. Neither
// ext-pcntl nor ext-posix ships in the bundled PHP, so stdin is the last signal.

// $withHostPipe is what makes the no-host case reproducible rather than
// inherited. Asking it of the RUNNER answers whatever spawned the runner: a
// paratest worker is started with a pipe on every descriptor, so the assertion
// that mattered most — the watch stays silent where there is no host — skipped
// in the only job that ran it. A child given /dev/null has no host, always.
function hostPipeProbe(string $expression, bool $closeStdin, bool $withHostPipe = true): string
{
    $script = '<?php require '.var_export(base_path('vendor/autoload.php'), true).';'
        .'$app = require '.var_export(base_path('bootstrap/app.php'), true).';'
        .'$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
        .'usleep(200000);'
        .'echo '.$expression.' ? "YES" : "NO";';

    $path = tempnam(sys_get_temp_dir(), 'beatrax-pipe-').'.php';
    file_put_contents($path, $script);

    $descriptors = [
        0 => $withHostPipe ? ['pipe', 'r'] : ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, $path], $descriptors, $pipes, base_path());

    if (! is_resource($process)) {
        unlink($path);
        test()->markTestSkipped('could not spawn a child process here');
    }

    if ($withHostPipe && $closeStdin) {
        fclose($pipes[0]);
    }

    $out = (string) stream_get_contents($pipes[1]);

    if ($withHostPipe && ! $closeStdin) {
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

// A hand-run artisan command has no host pipe, and the watch must stay silent
// there. The premise is built rather than assumed: this used to read the test
// runner's own stdin and skip when it was a pipe, which under --parallel it
// always is.
it('never claims a host has gone when there was none', function (): void {
    $watch = 'Modules\Core\Public\Services\HostPipeWatch';

    expect(hostPipeProbe($watch.'::isHeldByHost()', closeStdin: false, withHostPipe: false))
        ->toContain('NO');

    expect(hostPipeProbe($watch.'::hostHasGone()', closeStdin: false, withHostPipe: false))
        ->toContain('NO');
});
