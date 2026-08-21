<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Internal\Logging\LogFileStats;

function statsSandbox(): string
{
    $sandboxRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-logstats-'.bin2hex(random_bytes(6));
    putenv('NATIVEPHP_STORAGE_PATH='.$sandboxRoot);
    $path = UserDataPathService::dailyLogFile();
    @mkdir(dirname($path), 0755, true);

    return $path;
}

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
});

it('returns zeroed counters when today\'s log file is missing', function (): void {
    // The sandbox helper makes the directory but never the file.
    $path = statsSandbox();

    $stats = (new LogFileStats)->forToday();

    expect($stats['exists'])->toBeFalse();
    expect($stats['path'])->toBe($path);
    expect($stats['sizeBytes'])->toBe(0);
    expect($stats['totalLines'])->toBe(0);
    expect($stats['parsedLines'])->toBe(0);
    expect($stats['capped'])->toBeFalse();
    foreach (['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'] as $sev) {
        expect($stats['perSeverity'][$sev])->toBe(0);
    }
});

it('counts each Monolog severity correctly across a multi-level file', function (): void {
    $path = statsSandbox();
    $body = implode("\n", [
        '[2026-05-28 10:00:00] local.DEBUG: debug 1',
        '[2026-05-28 10:00:01] local.DEBUG: debug 2',
        '[2026-05-28 10:00:02] local.INFO: info 1',
        '[2026-05-28 10:00:03] local.INFO: info 2',
        '[2026-05-28 10:00:04] local.INFO: info 3',
        '[2026-05-28 10:00:05] local.WARNING: warning 1',
        '[2026-05-28 10:00:06] local.ERROR: error 1',
        '[2026-05-28 10:00:07] local.ERROR: error 2',
        '[2026-05-28 10:00:08] local.CRITICAL: critical 1',
        '[2026-05-28 10:00:09] local.EMERGENCY: emergency 1',
    ])."\n";
    file_put_contents($path, $body);

    $stats = (new LogFileStats)->forToday();

    expect($stats['exists'])->toBeTrue();
    expect($stats['sizeBytes'])->toBe(strlen($body));
    expect($stats['totalLines'])->toBe(10);
    expect($stats['parsedLines'])->toBe(10);
    expect($stats['perSeverity'])->toMatchArray([
        'DEBUG' => 2,
        'INFO' => 3,
        'NOTICE' => 0,
        'WARNING' => 1,
        'ERROR' => 2,
        'CRITICAL' => 1,
        'ALERT' => 0,
        'EMERGENCY' => 1,
    ]);
});

it('treats continuation lines (stack-trace rows, JSON tails) as totalLines but not as parsed entries', function (): void {
    $path = statsSandbox();
    $body = implode("\n", [
        '[2026-05-28 10:00:00] local.ERROR: boom',
        '#0 /var/www/app/Foo.php(42): something()',
        '#1 /var/www/app/Bar.php(13): other()',
        '{"context":"more info"}',
        '[2026-05-28 10:00:01] local.INFO: next',
    ])."\n";
    file_put_contents($path, $body);

    $stats = (new LogFileStats)->forToday();

    // 5 raw lines: 2 entry headers, 3 continuations.
    expect($stats['totalLines'])->toBe(5);
    expect($stats['parsedLines'])->toBe(2);
    expect($stats['perSeverity']['ERROR'])->toBe(1);
    expect($stats['perSeverity']['INFO'])->toBe(1);
});

it('sums file sizes across every laravel-*.log file in the daily directory', function (): void {
    statsSandbox();
    $dir = UserDataPathService::logsDirectory();

    $files = [
        $dir.'/laravel-2026-05-26.log' => str_repeat('a', 100),
        $dir.'/laravel-2026-05-27.log' => str_repeat('b', 250),
        $dir.'/laravel-2026-05-28.log' => str_repeat('c', 75),
        // 999 bytes the glob must not add to the total.
        $dir.'/notes.txt' => str_repeat('z', 999),
    ];
    foreach ($files as $file => $contents) {
        file_put_contents($file, $contents);
    }

    $all = (new LogFileStats)->allFiles();

    expect($all['count'])->toBe(3);
    expect($all['totalBytes'])->toBe(425);
});

it('truncates today\'s file to zero bytes and returns the bytes freed', function (): void {
    $path = statsSandbox();
    file_put_contents($path, str_repeat('x', 4096));
    expect(filesize($path))->toBe(4096);

    $freed = (new LogFileStats)->truncateToday();

    expect($freed)->toBe(4096);
    clearstatcache(true, $path);
    expect(filesize($path))->toBe(0);
    // The inode has to survive, or the tailer's size-shrink detection has
    // nothing to resume against.
    expect(is_file($path))->toBeTrue();
});

it('truncateToday returns 0 and is a no-op when today\'s file is missing', function (): void {
    $path = statsSandbox();

    $freed = (new LogFileStats)->truncateToday();

    expect($freed)->toBe(0);
    expect(is_file($path))->toBeFalse();
});
