<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Internal\Logging\RecentLogEntriesReader;

function readerWithLogFixture(string $fixture): RecentLogEntriesReader
{
    $logFile = UserDataPathService::dailyLogFile();
    $logDir = dirname($logFile);
    if (! is_dir($logDir)) {
        @mkdir($logDir, 0700, true);
    }
    file_put_contents($logFile, $fixture);

    return app(RecentLogEntriesReader::class);
}

afterEach(function (): void {
    $logFile = UserDataPathService::dailyLogFile();
    if (is_file($logFile)) {
        @unlink($logFile);
    }
});

it('returns an empty array when the daily log file is missing', function (): void {
    $logFile = UserDataPathService::dailyLogFile();
    if (is_file($logFile)) {
        @unlink($logFile);
    }
    $reader = app(RecentLogEntriesReader::class);

    expect($reader->recent(5))->toBe([]);
});

it('parses standard Laravel log lines into timestamp + channel + severity + message', function (): void {
    $reader = readerWithLogFixture(
        '[2026-05-24 12:00:01] local.INFO: hello world'.PHP_EOL.
        '[2026-05-24 12:00:02] local.ERROR: boom happened'.PHP_EOL,
    );

    $entries = $reader->recent(5);

    expect($entries)->toHaveCount(2);
    expect($entries[0]['timestamp'])->toBe('2026-05-24 12:00:01');
    expect($entries[0]['channel'])->toBe('local');
    expect($entries[0]['severity'])->toBe('INFO');
    expect($entries[0]['message'])->toBe('hello world');
    expect($entries[1]['severity'])->toBe('ERROR');
    expect($entries[1]['message'])->toBe('boom happened');
});

it('uppercases the severity even when the source line uses mixed case', function (): void {
    $reader = readerWithLogFixture('[2026-05-24 12:00:01] local.warning: lowercase severity'.PHP_EOL);

    $entries = $reader->recent(5);

    expect($entries)->toHaveCount(1);
    expect($entries[0]['severity'])->toBe('WARNING');
});

it('caps the returned list at the requested limit, returning the most recent entries', function (): void {
    $lines = '';
    for ($i = 1; $i <= 10; $i++) {
        $lines .= "[2026-05-24 12:00:0{$i}] local.INFO: line {$i}".PHP_EOL;
    }
    $reader = readerWithLogFixture($lines);

    $entries = $reader->recent(5);

    expect($entries)->toHaveCount(5);
    // Most recent 5 — lines 6..10.
    expect($entries[0]['message'])->toBe('line 6');
    expect($entries[4]['message'])->toBe('line 10');
});

it('folds continuation lines into the preceding entry rather than emitting standalone rows', function (): void {
    $reader = readerWithLogFixture(
        '[2026-05-24 12:00:01] local.ERROR: outer boom'.PHP_EOL.
        '#0 /app/foo.php(12): doStuff()'.PHP_EOL.
        '#1 /app/bar.php(34): foo()'.PHP_EOL.
        '[2026-05-24 12:00:02] local.INFO: next entry'.PHP_EOL,
    );

    $entries = $reader->recent(5);

    expect($entries)->toHaveCount(2);
    expect($entries[0]['severity'])->toBe('ERROR');
    expect($entries[0]['message'])->toContain('outer boom');
    expect($entries[0]['message'])->toContain('#0 /app/foo.php');
});

it('truncates very long messages with an ellipsis so dashboard rows stay single-line', function (): void {
    $longMessage = str_repeat('lorem ', 60); // ~360 chars
    $reader = readerWithLogFixture('[2026-05-24 12:00:01] local.INFO: '.$longMessage.PHP_EOL);

    $entries = $reader->recent(5);

    expect($entries)->toHaveCount(1);
    expect(mb_strlen($entries[0]['message']))->toBeLessThan(200);
    expect($entries[0]['message'])->toEndWith('…');
});

it('scrubs sensitive payloads from the rendered message via RedactSecretsProcessor', function (): void {
    $reader = readerWithLogFixture(
        '[2026-05-24 12:00:01] local.WARNING: Authorization: Bearer abcdefghi1234567890'.PHP_EOL,
    );

    $entries = $reader->recent(5);

    expect($entries)->toHaveCount(1);
    expect($entries[0]['message'])->not->toContain('abcdefghi1234567890');
    expect($entries[0]['message'])->toContain('[REDACTED]');
});

it('emits a /dev/logs deep-link href with severity + contains query parameters', function (): void {
    $reader = readerWithLogFixture(
        '[2026-05-24 12:00:01] local.ERROR: cannot reach upstream'.PHP_EOL,
    );

    $entries = $reader->recent(5);

    expect($entries)->toHaveCount(1);
    expect($entries[0]['href'])->toStartWith('/dev/logs?severities=ERROR&contains=');
    expect($entries[0]['href'])->toContain('cannot');
});

it('href contains filter NEVER includes the ellipsis truncation suffix (user-reported regression)', function (): void {
    // `contains` is a literal substring match against the source log line,
    // and '…' never appears there. Carrying the truncation marker into the
    // href made every deep-link from a long message return zero rows.
    $longMessage = str_repeat('lorem ', 60); // ~360 chars
    $reader = readerWithLogFixture('[2026-05-24 12:00:01] local.ERROR: '.$longMessage.PHP_EOL);

    $entries = $reader->recent(5);

    expect($entries)->toHaveCount(1);
    expect($entries[0]['message'])->toEndWith('…');
    expect($entries[0]['href'])->not->toContain('%E2%80%A6'); // urlencoded '…'
    expect($entries[0]['href'])->not->toContain('…');
});

it('drops lines that do not match the Laravel log format when no preceding entry exists to fold into', function (): void {
    $reader = readerWithLogFixture(
        'junk line at the start'.PHP_EOL.
        'another junk line'.PHP_EOL.
        '[2026-05-24 12:00:01] local.INFO: first real entry'.PHP_EOL,
    );

    $entries = $reader->recent(5);

    expect($entries)->toHaveCount(1);
    expect($entries[0]['message'])->toBe('first real entry');
});

it('reads the tail of a large daily log without loading the whole file into memory', function (): void {
    // The pane backs /dev, the page opened BECAUSE something is wrong, and
    // file() on a multi-megabyte log tripled the request's peak memory.
    $line = '[2026-05-24 12:00:01] local.INFO: '.str_repeat('x', 480).PHP_EOL;
    $logFile = UserDataPathService::dailyLogFile();
    $logDir = dirname($logFile);
    if (! is_dir($logDir)) {
        @mkdir($logDir, 0700, true);
    }
    $handle = fopen($logFile, 'wb');
    for ($i = 0; $i < 16_000; $i++) {
        fwrite($handle, $line);
    }
    fwrite($handle, '[2026-05-24 12:00:02] local.ERROR: the last line'.PHP_EOL);
    fclose($handle);
    expect(filesize($logFile))->toBeGreaterThan(8_000_000);

    $reader = app(RecentLogEntriesReader::class);

    memory_reset_peak_usage();
    $before = memory_get_usage(true);
    $entries = $reader->recent(5);
    $peakGrowth = memory_get_peak_usage(true) - $before;

    expect($entries[4]['message'])->toBe('the last line');
    expect($peakGrowth)->toBeLessThan(4_000_000);
});

// /dev/logs matches `contains` with a literal String.includes() against the
// message it parsed off the line. Collapsing runs of whitespace in the needle
// therefore made the deep-link miss its own row — and `PHP Warning:  Undefined
// array key` carries PHP's own two spaces, which is the most common ERROR shape
// in a Laravel log.
it('builds a contains needle that is a literal substring of the source message', function (): void {
    $message = 'PHP Warning:  Undefined array key "iban" in /app/Modules/Ledger/Import.php on line 42';
    $reader = readerWithLogFixture('[2026-05-24 12:00:01] local.ERROR: '.$message.PHP_EOL);

    $entries = $reader->recent(5);

    expect($entries)->toHaveCount(1);
    parse_str((string) parse_url($entries[0]['href'], PHP_URL_QUERY), $query);
    $needle = $query['contains'];

    expect($needle)->not->toBe('');
    expect($needle)->toContain('  ');
    expect(str_contains($message, $needle))->toBeTrue(
        'the contains needle must appear verbatim in the line it deep-links to',
    );
});

it('keeps the needle on the entry own line rather than running into a folded stack trace', function (): void {
    $reader = readerWithLogFixture(
        '[2026-05-24 12:00:01] local.ERROR: short boom'.PHP_EOL.
        '#0 /app/foo.php(12): doStuff()'.PHP_EOL,
    );

    $entries = $reader->recent(5);

    parse_str((string) parse_url($entries[0]['href'], PHP_URL_QUERY), $query);

    expect($query['contains'])->toBe('short boom');
});
