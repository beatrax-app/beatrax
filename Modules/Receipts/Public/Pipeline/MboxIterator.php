<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Pipeline;

use Generator;
use RuntimeException;

/**
 * Streaming iterator over an mbox archive (.mbox file).
 *
 * Implements the mboxrd convention:
 *
 *   - Messages are separated by lines starting with the literal ASCII
 *     bytes `From ` (followed by an envelope-sender + date). The
 *     separator line itself is NOT part of the message body.
 *   - Bodies that legitimately contain a line starting with `From `
 *     are escaped during write by prefixing one or more `>`
 *     characters; the iterator strips ONE leading `>` from any line
 *     matching `^>+From `.
 *
 * The implementation uses fopen('rb') + fgets() line-by-line so the
 * peak memory footprint is bounded by the largest single message in
 * the archive — never the file size. A 5-year iCloud export can be
 * GB-scale; a whole-file read would exhaust RAM. The Pest test that
 * drives a synthetic 50 MB mbox asserts streaming-correctness via a
 * peak-memory delta bound rather than absolute peak (Pest's own
 * runtime baseline already exceeds 64 MB before the test starts).
 *
 * Each yielded element exposes the raw RFC 822 bytes plus the
 * byte-offset and zero-based index for audit traceability.
 *
 * @phpstan-type MboxYield array{eml: string, byteOffset: int, index: int}
 */
final class MboxIterator
{
    /**
     * @return Generator<int, array{eml: string, byteOffset: int, index: int}>
     */
    public function iterate(string $mboxPath): Generator
    {
        $fh = @fopen($mboxPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException("MboxIterator: cannot open mbox at {$mboxPath}.");
        }
        try {
            $buffer = '';
            $index = 0;
            $offset = 0;
            $inMessage = false;
            while (($line = fgets($fh)) !== false) {
                if (str_starts_with($line, 'From ')) {
                    if ($inMessage && $buffer !== '') {
                        yield ['eml' => $buffer, 'byteOffset' => $offset, 'index' => $index];
                        $index++;
                    }
                    $buffer = '';
                    $offset = (int) ftell($fh);
                    $inMessage = true;

                    continue;
                }

                // mboxrd unescape: strip ONE leading '>' from lines
                // matching '^>+From '. Bodies that contained a literal
                // 'From ' at the start of a line were escaped with
                // one or more '>' on write; we unescape exactly one
                // level here.
                if ($inMessage && preg_match('/^>+From /', $line) === 1) {
                    $line = substr($line, 1);
                }

                $buffer .= $line;
            }
            if ($inMessage && $buffer !== '') {
                yield ['eml' => $buffer, 'byteOffset' => $offset, 'index' => $index];
            }
        } finally {
            fclose($fh);
        }
    }
}
