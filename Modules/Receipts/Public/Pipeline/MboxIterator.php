<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Pipeline;

use Generator;
use Modules\Core\Public\Support\UploadLimits;
use Modules\Receipts\Public\Exceptions\MboxReadException;
use Modules\Receipts\Public\Support\ReceiptCaptureLog;

// Streaming mboxrd iterator: messages split on lines starting with the
// literal "From " (stripped); an escaped body line ('>From ') is
// unescaped by one level. Peak memory is bounded by the largest
// single message, never the archive size.
final class MboxIterator
{
    /**
     * @param  ReceiptCaptureLog|null  $captures  Told the ordinal of every message this could not carve out, so a caller can report the archive it skipped part of rather than importing fewer messages than the reader handed over. A caller that passes none has nowhere to put that fact, and gets MboxReadException instead of a quietly shorter archive.
     * @return Generator<int, array{eml: string, byteOffset: int, index: int}>
     *
     * @throws MboxReadException
     */
    public function iterate(string $mboxPath, ?ReceiptCaptureLog $captures = null): Generator
    {
        $fh = @fopen($mboxPath, 'rb');

        if ($fh === false) {
            throw MboxReadException::couldNotOpen($mboxPath);
        }

        try {
            $state = ['buffer' => '', 'offset' => 0, 'index' => 0, 'inMessage' => false, 'overflowed' => false];

            while (($line = fgets($fh)) !== false) {
                if (str_starts_with($line, 'From ')) {
                    yield from $this->closeMessage($state, $captures);

                    $state = ['buffer' => '', 'offset' => (int) ftell($fh), 'index' => $state['index'], 'inMessage' => true, 'overflowed' => false];

                    continue;
                }

                if ($state['overflowed']) {
                    continue;
                }

                $state['buffer'] .= $state['inMessage'] ? $this->unescaped($line) : $line;

                if ($this->overruns($state['buffer'], $captures, $mboxPath)) {
                    $state['buffer'] = '';
                    $state['overflowed'] = true;
                }
            }

            yield from $this->closeMessage($state, $captures);
        } finally {
            fclose($fh);
        }
    }

    /**
     * @param  array{buffer: string, offset: int, index: int, inMessage: bool, overflowed: bool}  $state
     * @return Generator<int, array{eml: string, byteOffset: int, index: int}>
     */
    private function closeMessage(array &$state, ?ReceiptCaptureLog $captures): Generator
    {
        if ($state['overflowed']) {
            $captures?->recordUnreadable($state['index']);
            $state['index']++;

            return;
        }

        if ($state['inMessage'] && $state['buffer'] !== '') {
            yield ['eml' => $state['buffer'], 'byteOffset' => $state['offset'], 'index' => $state['index']];
            $state['index']++;
        }
    }

    // A delimiter-less run of bytes would grow the buffer without bound, so it
    // is dropped and the read resumes at the next delimiter. An archive is
    // independent documents: the rest of them are not this one's to take down
    // with it. A caller with nowhere to record that gets the exception instead.
    private function overruns(string $buffer, ?ReceiptCaptureLog $captures, string $mboxPath): bool
    {
        if (strlen($buffer) <= UploadLimits::MAX_MESSAGE_BYTES) {
            return false;
        }

        if ($captures === null) {
            throw MboxReadException::messageTooLarge($mboxPath);
        }

        return true;
    }

    private function unescaped(string $line): string
    {
        return preg_match('/^>+From /', $line) === 1 ? substr($line, 1) : $line;
    }
}
