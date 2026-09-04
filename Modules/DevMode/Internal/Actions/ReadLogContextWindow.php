<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Actions;

use DateTimeImmutable;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\DevMode\Internal\Logging\ActiveLogFile;
use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use SplFileObject;

final readonly class ReadLogContextWindow
{
    use CoercesScalars;

    public const int MAX_RADIUS = 50;

    public function __construct(
        private RedactSecretsProcessor $processor,
        private ActiveLogFile $file,
    ) {}

    /**
     * @return array{date: string, line: int, radius: int, lines: list<array{index: int, text: string}>, total: int}
     */
    public function __invoke(mixed $date, mixed $line, mixed $radius): array
    {
        $day = is_string($date) && $date !== '' ? new DateTimeImmutable($date) : new DateTimeImmutable;
        $targetLine = self::toInt($line);
        $window = self::toInt($radius);

        $path = $this->file->path($day);
        if (! is_file($path) || ! is_readable($path)) {
            return [
                'date' => $day->format('Y-m-d'),
                'line' => $targetLine,
                'radius' => $window,
                'lines' => [],
                'total' => 0,
            ];
        }

        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::DROP_NEW_LINE);

        // SplFileObject has no cheap line count short of iterating;
        // seek to end + key() returns the last line index (0-based).
        $file->seek(PHP_INT_MAX);
        $total = $file->key() + 1;

        // Clamped before the window is sized: an out-of-range ?line=999999
        // against a 5-line file would otherwise give start > end and no rows.
        $targetLine = min(max(0, $targetLine), max(0, $total - 1));

        return [
            'date' => $day->format('Y-m-d'),
            'line' => $targetLine,
            'radius' => $window,
            'lines' => $this->scrubbedRange($file, max(0, $targetLine - $window), min($total - 1, $targetLine + $window)),
            'total' => $total,
        ];
    }

    /**
     * @return list<array{index: int, text: string}>
     */
    private function scrubbedRange(SplFileObject $file, int $start, int $end): array
    {
        $out = [];
        $file->seek($start);
        for ($i = $start; $i <= $end; $i++) {
            $line = $file->current();
            if (! is_string($line)) {
                break;
            }
            $out[] = [
                'index' => $i,
                'text' => $this->processor->scrub($line),
            ];
            $file->next();
        }

        return $out;
    }
}
