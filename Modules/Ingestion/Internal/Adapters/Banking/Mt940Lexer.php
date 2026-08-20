<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Generator;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;

final class Mt940Lexer
{
    // UploadLimits bounds bytes, not lines, so a small but degenerate file —
    // every byte a newline — still needs its own cap.
    private const MAX_LINE_COUNT = 100_000;

    // Real :86: narratives never exceed a few hundred bytes. Doubles as the
    // stream_get_line cap so one huge line cannot allocate before the check.
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

                if (preg_match(Mt940HeaderProfile::SWIFT_ENVELOPE_REGEX, $whole, $matches) !== 1) {
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

        while (($raw = stream_get_line($handle, self::MAX_BUFFER_BYTES + 1, "\n")) !== false) {
            if (++$lineCount > self::MAX_LINE_COUNT) {
                throw new InvalidAmountException(sprintf(
                    'MT940 line limit exceeded (%d).',
                    self::MAX_LINE_COUNT,
                ));
            }

            if (strlen($raw) > self::MAX_BUFFER_BYTES) {
                throw new InvalidAmountException(sprintf(
                    'MT940 line exceeds buffer cap (%d bytes).',
                    self::MAX_BUFFER_BYTES,
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
