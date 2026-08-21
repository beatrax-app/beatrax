<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Pipeline;

use Generator;
use Modules\Receipts\Public\Exceptions\MboxReadException;
use Modules\Receipts\Public\Support\UploadLimits;

// Streaming mboxrd iterator: messages split on lines starting with the
// literal "From " (stripped); an escaped body line ('>From ') is
// unescaped by one level. Peak memory is bounded by the largest
// single message, never the archive size.
final class MboxIterator
{
    /**
     * @return Generator<int, array{eml: string, byteOffset: int, index: int}>
     *
     * @throws MboxReadException
     */
    public function iterate(string $mboxPath): Generator
    {
        $fh = @fopen($mboxPath, 'rb');
        if ($fh === false) {
            throw MboxReadException::couldNotOpen($mboxPath);
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

                if ($inMessage && preg_match('/^>+From /', $line) === 1) {
                    $line = substr($line, 1);
                }

                $buffer .= $line;

                // "From "-less input (a corrupt archive, or a single crafted
                // message with no delimiter) would otherwise grow the buffer
                // without bound; cap one message so the archive is quarantined
                // rather than exhausting the worker's memory.
                if (strlen($buffer) > UploadLimits::MAX_MESSAGE_BYTES) {
                    throw MboxReadException::messageTooLarge($mboxPath);
                }
            }
            if ($inMessage && $buffer !== '') {
                yield ['eml' => $buffer, 'byteOffset' => $offset, 'index' => $index];
            }
        } finally {
            fclose($fh);
        }
    }
}
