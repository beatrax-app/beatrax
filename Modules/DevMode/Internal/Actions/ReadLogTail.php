<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Actions;

use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\DevMode\Internal\Logging\ActiveLogFile;
use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Modules\DevMode\Internal\Process\FileTailer;

/**
 * @link ../../../../.docs/features/dev-mode/architecture.md#secret-redaction-pipeline
 */
final readonly class ReadLogTail
{
    use CoercesScalars;

    public function __construct(
        private FileTailer $tailer,
        private RedactSecretsProcessor $processor,
        private ActiveLogFile $file,
    ) {}

    /**
     * @return array{chunk: string, newOffset: int, inode: ?int, reset: bool}
     */
    public function __invoke(mixed $since, mixed $clientInode): array
    {
        $offset = self::toInt($since);
        $inode = self::nullableInt($clientInode);

        $path = $this->file->path();
        $currentInode = self::inodeOf($path);
        $reset = self::rotated($inode, $currentInode, $offset, self::sizeOf($path) ?? 0);
        $offset = $reset ? 0 : $offset;

        $window = self::wholeLinesOnly($this->tailer->tailOnce($path, $offset), $offset);

        return [
            'chunk' => $window['chunk'] === '' ? '' : $this->processor->scrub($window['chunk']),
            'newOffset' => $window['newOffset'],
            'inode' => $currentInode,
            'reset' => $reset,
        ];
    }

    // Two rotation shapes: a new inode (truncate+rename, day rollover) and an
    // offset past EOF (copytruncate). Both mean "zero your cursor".
    private static function rotated(?int $clientInode, ?int $currentInode, int $offset, int $currentSize): bool
    {
        return ($clientInode !== null && $currentInode !== null && $clientInode !== $currentInode)
            || $offset > $currentSize;
    }

    // Redaction is a pattern match, so a secret split across the tailer's
    // fixed byte window matches in neither half and both halves reach the
    // browser. The trailing partial line is held back and the cursor rewound
    // to it, so the next poll sees that line whole.
    /**
     * @param  array{chunk: string, newOffset: int}  $result
     * @return array{chunk: string, newOffset: int}
     */
    private static function wholeLinesOnly(array $result, int $offset): array
    {
        $chunk = $result['chunk'];
        if ($chunk === '' || str_ends_with($chunk, "\n")) {
            return $result;
        }

        $lastBreak = strrpos($chunk, "\n");
        if ($lastBreak === false) {
            // One line longer than the whole window: nothing can be shown yet
            // without the risk of halving a secret.
            return ['chunk' => '', 'newOffset' => $offset];
        }

        return [
            'chunk' => substr($chunk, 0, $lastBreak + 1),
            'newOffset' => $result['newOffset'] - (strlen($chunk) - ($lastBreak + 1)),
        ];
    }

    private static function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private static function inodeOf(string $path): ?int
    {
        clearstatcache(true, $path);
        if (! is_file($path)) {
            return null;
        }
        $stat = @stat($path);
        if ($stat === false) {
            return null;
        }

        return $stat['ino'];
    }

    private static function sizeOf(string $path): ?int
    {
        clearstatcache(true, $path);
        if (! is_file($path)) {
            return null;
        }
        $size = @filesize($path);

        return $size === false ? null : $size;
    }
}
