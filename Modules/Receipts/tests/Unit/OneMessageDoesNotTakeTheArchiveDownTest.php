<?php

declare(strict_types=1);

use Modules\Core\Public\Support\UploadLimits;
use Modules\Receipts\Public\Exceptions\MboxReadException;
use Modules\Receipts\Public\Pipeline\MboxIterator;
use Modules\Receipts\Public\Support\ReceiptCaptureLog;

// A statement truncated mid-file is a silent gap in a continuous ledger, so
// refusing the whole file is the safe answer there. An mbox is a concatenation
// of independent documents: message 400 exceeding the per-message ceiling says
// nothing about messages 1 to 399, and taking them with it loses 399 receipts.
function mboxOfThreeWhoseMiddleMessageIsOversized(bool $oversize): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'beatrax-mbox-');

    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Could not open the archive fixture for writing.');
    }

    foreach ([1, 2, 3] as $index) {
        fwrite($handle, "From sender{$index}@example.test Sun May 17 09:4{$index}:00 2026\n");
        fwrite($handle, "From: sender{$index}@example.test\n");
        fwrite($handle, "Subject: Synthetic archive message {$index}\n\n");
        fwrite($handle, "Body of message {$index}.\n");

        // 64 KB lines rather than short ones: the iterator reads line by line,
        // and the ceiling is measured in tens of megabytes.
        if ($index === 2 && $oversize) {
            $line = str_repeat('x', 65535)."\n";
            for ($written = 0; $written <= UploadLimits::MAX_MESSAGE_BYTES; $written += strlen($line)) {
                fwrite($handle, $line);
            }
        }
    }

    fclose($handle);

    return $path;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/beatrax-mbox-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

it('carries on past a message it cannot carve out and names the one it dropped', function (): void {
    $path = mboxOfThreeWhoseMiddleMessageIsOversized(oversize: true);
    $captures = new ReceiptCaptureLog;

    $messages = iterator_to_array((new MboxIterator)->iterate($path, $captures), false);

    // The ordinal is the message's place in the archive, not the yield
    // counter: a dropped message that did not advance it would name the wrong
    // document and, in the preview, collide with a row that did read.
    expect($messages)->toHaveCount(2)
        ->and($messages[0]['eml'])->toContain('Synthetic archive message 1')
        ->and($messages[1]['eml'])->toContain('Synthetic archive message 3')
        ->and(array_column($messages, 'index'))->toBe([0, 2])
        ->and($captures->unreadableIndexes())->toBe([1]);
});

// The positive control. Without it the assertions above would pass against an
// iterator that had simply stopped yielding anything at all.
it('yields every message and drops none when they all fit', function (): void {
    $path = mboxOfThreeWhoseMiddleMessageIsOversized(oversize: false);
    $captures = new ReceiptCaptureLog;

    $messages = iterator_to_array((new MboxIterator)->iterate($path, $captures), false);

    expect($messages)->toHaveCount(3)
        ->and($messages[1]['eml'])->toContain('Synthetic archive message 2')
        ->and($captures->unreadableIndexes())->toBe([]);
});

// The drop-folder scan hands no capture log, because it has no preview to put
// a skipped ordinal on. Skipping quietly there would move a shorter archive to
// processed/ with nothing recording the message it dropped, so that caller
// keeps the refusal and its file lands in failed/ where the reader can see it.
it('still refuses the archive for a caller with nowhere to record the skip', function (): void {
    $path = mboxOfThreeWhoseMiddleMessageIsOversized(oversize: true);

    expect(function () use ($path): void {
        foreach ((new MboxIterator)->iterate($path) as $_) {
            // iterate() is lazy, so the read only happens once it is advanced.
        }
    })->toThrow(MboxReadException::class, $path);
});
