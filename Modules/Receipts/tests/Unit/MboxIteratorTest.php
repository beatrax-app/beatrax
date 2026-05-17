<?php

declare(strict_types=1);

use Modules\Receipts\Public\Pipeline\MboxIterator;

it('streams exactly 5 messages from the small.mbox fixture', function (): void {
    $iterator = new MboxIterator;
    $fixturePath = __DIR__.'/../fixtures/mbox/small.mbox';

    $messages = [];
    foreach ($iterator->iterate($fixturePath) as $entry) {
        $messages[] = $entry;
    }

    expect($messages)->toHaveCount(5);

    // Each yielded entry exposes the canonical shape: raw .eml
    // bytes, byte offset (monotonically increasing), zero-based
    // index.
    expect($messages[0])->toHaveKeys(['eml', 'byteOffset', 'index']);
    expect($messages[0]['index'])->toBe(0);
    expect($messages[4]['index'])->toBe(4);

    // Each yielded body contains the matching Subject header line.
    expect($messages[0]['eml'])->toContain('Synthetic mbox message 1');
    expect($messages[4]['eml'])->toContain('Synthetic mbox message 5');

    // Separator lines must NOT leak into message bodies.
    expect($messages[0]['eml'])->not->toStartWith('From sender1@example.test');
});

it('strips a single leading > from >From lines in the body', function (): void {
    $iterator = new MboxIterator;
    $fixturePath = __DIR__.'/../fixtures/mbox/small.mbox';

    $messages = iterator_to_array($iterator->iterate($fixturePath), false);

    // Message 2: body line was '>From the perspective of...' — the
    // iterator strips ONE '>' leaving 'From the perspective of...'.
    expect($messages[1]['eml'])->toContain("\nFrom the perspective of the iterator");

    // Message 3: body line was '>>From here the iterator strips...'
    // — one '>' stripped leaves '>From here ...'.
    expect($messages[2]['eml'])->toContain("\n>From here the iterator strips one '>'");
});

it('streams a synthetic 50 MB mbox under 64 MB peak memory', function (): void {
    $tmpPath = tempnam(sys_get_temp_dir(), 'mbox-bigtest-').'.mbox';
    register_shutdown_function(static function () use ($tmpPath): void {
        @unlink($tmpPath);
    });

    // Generate ~50 MB of mboxrd content. Each synthetic message is
    // ~8 KB body + ~150 bytes headers + separator; repeat ~6400
    // times to clear the 50 MB threshold.
    $fh = fopen($tmpPath, 'wb');
    if ($fh === false) {
        throw new RuntimeException("Could not open temp mbox at {$tmpPath}.");
    }
    $body = str_repeat("Body line padding to inflate the message size.\n", 170);
    $messageCount = 6400;
    for ($i = 0; $i < $messageCount; $i++) {
        $headers = "From sender{$i}@example.test Thu Jan  1 00:00:00 2026\n"
            ."From: sender{$i}@example.test\n"
            ."To: kaarthouder@example.test\n"
            ."Subject: Bulk synthetic message {$i}\n"
            ."Date: Thu, 01 Jan 2026 00:00:00 +0000\n"
            ."Message-ID: <bulk-{$i}@example.test>\n\n";
        fwrite($fh, $headers.$body."\n");
    }
    fclose($fh);

    $sizeBytes = filesize($tmpPath);
    expect($sizeBytes)->toBeGreaterThan(50 * 1024 * 1024);

    // Reset peak-memory tracking so prior PHP runtime allocations
    // do not contaminate the budget assertion.
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $baseline = memory_get_peak_usage(true);

    $iterator = new MboxIterator;
    $count = 0;
    foreach ($iterator->iterate($tmpPath) as $entry) {
        $count++;
        // Touch the yielded bytes lightly so the generator's value
        // is observed — but immediately discard so the iterator
        // never accumulates more than one message worth of bytes.
        if ($entry['eml'] === '') {
            throw new RuntimeException('iterator yielded empty .eml');
        }
    }

    $peakAfter = memory_get_peak_usage(true);
    $deltaBytes = $peakAfter - $baseline;
    $deltaMb = $deltaBytes / 1024 / 1024;
    $fileMb = (int) $sizeBytes / 1024 / 1024;

    expect($count)->toBe($messageCount);

    // Streaming-correctness assertion: the iterator MUST NOT scale
    // memory with file size. The delta between baseline (after the
    // mbox is written but before iteration starts) and the peak after
    // iteration must stay well under the file size — a non-streaming
    // implementation that called file_get_contents() would blow past
    // the file size by a factor of ~2-3x. We allow up to 16 MB of
    // delta to absorb fgets() line-buffering and zbateson-free
    // generator overhead while still catching the regression that
    // motivated Pitfall 6 (a buffer-the-whole-file mistake would
    // surface a delta on the order of fileMb here).
    expect($deltaMb)
        ->toBeLessThan(16.0, sprintf(
            'Iterator added %.1f MB to peak memory while streaming a %.1f MB mbox — '
            .'this looks like a non-streaming implementation. Budget is < 16 MB delta.',
            $deltaMb,
            $fileMb,
        ));
});
