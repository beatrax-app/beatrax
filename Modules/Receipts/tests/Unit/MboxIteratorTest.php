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

    expect($messages[0])->toHaveKeys(['eml', 'byteOffset', 'index']);
    expect($messages[0]['index'])->toBe(0);
    expect($messages[4]['index'])->toBe(4);

    expect($messages[0]['eml'])->toContain('Synthetic mbox message 1');
    expect($messages[4]['eml'])->toContain('Synthetic mbox message 5');

    // The "From " separator line must not leak into the body it precedes.
    expect($messages[0]['eml'])->not->toStartWith('From sender1@example.test');
});

it('strips a single leading > from >From lines in the body', function (): void {
    $iterator = new MboxIterator;
    $fixturePath = __DIR__.'/../fixtures/mbox/small.mbox';

    $messages = iterator_to_array($iterator->iterate($fixturePath), false);

    // The fixture's second message body starts '>From the perspective of…';
    // exactly one '>' comes off.
    expect($messages[1]['eml'])->toContain("\nFrom the perspective of the iterator");

    // The third starts '>>From here…', so stripping one leaves the other behind.
    expect($messages[2]['eml'])->toContain("\n>From here the iterator strips one '>'");
});

it('streams a synthetic 50 MB mbox under 64 MB peak memory', function (): void {
    $tmpPath = tempnam(sys_get_temp_dir(), 'mbox-bigtest-').'.mbox';
    register_shutdown_function(static function () use ($tmpPath): void {
        @unlink($tmpPath);
    });

    // Roughly 8 KB of body plus headers per message, repeated enough times to
    // clear the 50 MB threshold the assertion below depends on.
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

    // Reset peak tracking so allocations from earlier in the run do not
    // contaminate the budget assertion.
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $baseline = memory_get_peak_usage(true);

    $iterator = new MboxIterator;
    $count = 0;
    foreach ($iterator->iterate($tmpPath) as $entry) {
        $count++;
        // Touch the yielded bytes so the generator's value is observed, then
        // drop them — holding on would defeat what is being measured.
        if ($entry['eml'] === '') {
            throw new RuntimeException('iterator yielded empty .eml');
        }
    }

    $peakAfter = memory_get_peak_usage(true);
    $deltaBytes = $peakAfter - $baseline;
    $deltaMb = $deltaBytes / 1024 / 1024;
    $fileMb = (int) $sizeBytes / 1024 / 1024;

    expect($count)->toBe($messageCount);

    // The iterator must not scale memory with file size: a buffer-the-whole-file
    // implementation would push the delta up to the order of the file itself.
    // The 16 MB budget leaves room for fgets() line buffering and generator
    // overhead while still catching that regression.
    expect($deltaMb)
        ->toBeLessThan(16.0, sprintf(
            'Iterator added %.1f MB to peak memory while streaming a %.1f MB mbox — '
            .'this looks like a non-streaming implementation. Budget is < 16 MB delta.',
            $deltaMb,
            $fileMb,
        ));
});
