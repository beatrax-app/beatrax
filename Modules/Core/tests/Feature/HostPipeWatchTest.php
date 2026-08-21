<?php

declare(strict_types=1);

use Modules\Core\Public\Services\HostPipeWatch;

// The shell spawns daemons and queue workers as persistent children on purpose,
// so a force quit skips the supervisor's stop hook and leaves them running — one
// queue listener was found alive three hours after the app died. Neither
// ext-pcntl nor ext-posix ships in the bundled PHP, so stdin is the last signal.

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
