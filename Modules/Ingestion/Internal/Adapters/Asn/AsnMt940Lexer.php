<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Asn;

use Generator;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;

/**
 * Streaming line-by-line tokenizer for MT940 statement files. Yields
 * `(tag, content)` tuples in stream order; the consumer is responsible for
 * pairing tags into transactions.
 *
 * Edge cases handled by the lexer:
 *  - SWIFT block-1 envelope `{1:...}{2:...}{4: ... -}` — block-4 contents
 *    are extracted and tokenized when the file head opens with `{`.
 *  - CRLF line endings — carriage returns are stripped at line-split;
 *    line-feed inside a continuation buffer is preserved so the consumer
 *    can re-scan `?NN` subfields across line boundaries.
 *  - Lone `-` end-of-message marker — flushes the current tag.
 *  - Trailing tag at EOF without a final `-` — flushed unconditionally.
 *  - Multi-statement files — every `:20:`-block contributes its tags in
 *    stream order; the adapter handles partitioning into statements.
 *
 * Bounded reads (defensive against pathological inputs):
 *  - Total line count is capped at `MAX_LINE_COUNT`. The wizard's
 *    `max:10240` rule already bounds input bytes; this guards against a
 *    crafted file whose every byte is a newline.
 *  - Each tag buffer is capped at `MAX_BUFFER_BYTES`. Real `:86:`
 *    narratives never exceed a few hundred bytes per tag.
 *
 * Both caps raise `InvalidAmountException` with a user-readable message so
 * the upload wizard surfaces a fast error instead of a hung worker.
 */
final class AsnMt940Lexer
{
    private const MAX_LINE_COUNT = 100_000;

    private const MAX_BUFFER_BYTES = 16_384;

    private const TAG_LINE_REGEX = '/^:(\d{2}[A-Z]?):(.*)$/';

    /**
     * @return Generator<int, array{0: string, 1: string}>
     */
    public function tokenize(string $localPath): Generator
    {
        $handle = @fopen($localPath, 'rb');
        if ($handle === false) {
            throw new InvalidAmountException(sprintf('Could not open MT940 file: %s', $localPath));
        }

        try {
            $head = (string) fread($handle, 8192);

            if (str_starts_with(ltrim($head), '{')) {
                rewind($handle);
                $whole = stream_get_contents($handle);
                if (! is_string($whole)) {
                    throw new InvalidAmountException('Could not read MT940 file body.');
                }

                if (preg_match(AsnMt940HeaderProfile::SWIFT_ENVELOPE_REGEX, $whole, $matches) !== 1) {
                    throw new InvalidAmountException('SWIFT envelope detected but block-4 contents missing.');
                }

                yield from $this->tokenizeBuffer($matches[1]);

                return;
            }

            rewind($handle);
            yield from $this->tokenizeStream($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return Generator<int, array{0: string, 1: string}>
     */
    private function tokenizeStream($handle): Generator
    {
        $currentTag = null;
        $buffer = '';
        $lineCount = 0;

        while (($raw = fgets($handle)) !== false) {
            if (++$lineCount > self::MAX_LINE_COUNT) {
                throw new InvalidAmountException(sprintf(
                    'MT940 line limit exceeded (%d).',
                    self::MAX_LINE_COUNT,
                ));
            }

            $line = rtrim($raw, "\r\n");
            yield from $this->processLine($line, $currentTag, $buffer);
        }

        if ($currentTag !== null) {
            yield [$currentTag, rtrim($buffer, "\r\n")];
        }
    }

    /**
     * @return Generator<int, array{0: string, 1: string}>
     */
    private function tokenizeBuffer(string $body): Generator
    {
        $split = preg_split('/\r\n|\r|\n/', $body);
        $lines = is_array($split) ? $split : [];

        $currentTag = null;
        $buffer = '';
        $lineCount = 0;

        foreach ($lines as $line) {
            if (++$lineCount > self::MAX_LINE_COUNT) {
                throw new InvalidAmountException(sprintf(
                    'MT940 line limit exceeded (%d).',
                    self::MAX_LINE_COUNT,
                ));
            }
            yield from $this->processLine($line, $currentTag, $buffer);
        }

        if ($currentTag !== null) {
            yield [$currentTag, rtrim($buffer, "\r\n")];
        }
    }

    /**
     * @return Generator<int, array{0: string, 1: string}>
     */
    private function processLine(string $line, ?string &$currentTag, string &$buffer): Generator
    {
        if ($line === '-') {
            if ($currentTag !== null) {
                yield [$currentTag, rtrim($buffer, "\r\n")];
            }
            $currentTag = null;
            $buffer = '';

            return;
        }

        if (preg_match(self::TAG_LINE_REGEX, $line, $m) === 1) {
            if ($currentTag !== null) {
                yield [$currentTag, rtrim($buffer, "\r\n")];
            }
            $currentTag = $m[1];
            $buffer = $m[2];
            $this->checkBufferSize($buffer);

            return;
        }

        if ($currentTag === null) {
            return;
        }

        $buffer .= "\n".$line;
        $this->checkBufferSize($buffer);
    }

    private function checkBufferSize(string $buffer): void
    {
        if (strlen($buffer) > self::MAX_BUFFER_BYTES) {
            throw new InvalidAmountException(sprintf(
                'MT940 tag buffer limit exceeded (%d bytes).',
                self::MAX_BUFFER_BYTES,
            ));
        }
    }
}
