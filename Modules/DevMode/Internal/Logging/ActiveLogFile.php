<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Logging;

use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Modules\Core\Public\Services\UserDataPathService;

/**
 * @link ../../../../.docs/features/dev-mode/the-file-the-log-viewer-opens.md
 */
final readonly class ActiveLogFile
{
    private const array FILE_DRIVERS = ['single', 'daily'];

    private const string STACK_DRIVER = 'stack';

    private const int MAX_STACK_DEPTH = 16;

    public function __construct(private Repository $config) {}

    public function path(?DateTimeInterface $date = null): string
    {
        return $this->rotatesDaily()
            ? UserDataPathService::dailyLogFile($date)
            : UserDataPathService::logsFile();
    }

    // `laravel-*.log` for a rotating channel; for a single-file channel the
    // literal path, which glob() answers with one entry when it exists and
    // none when it does not — the same shape the caller sums over.
    public function siblingGlob(): string
    {
        $base = UserDataPathService::logsFile();

        if (! $this->rotatesDaily()) {
            return $base;
        }

        $extension = pathinfo($base, PATHINFO_EXTENSION);

        return dirname($base)
            .DIRECTORY_SEPARATOR
            .pathinfo($base, PATHINFO_FILENAME)
            .'-*'
            .($extension !== '' ? '.'.$extension : '');
    }

    public function rotatesDaily(): bool
    {
        return $this->fileDriver() === 'daily';
    }

    // `daily` when the configured channel writes to no file at all: that is
    // this app's own default channel, so it is the shape the log takes
    // whenever there is a log to read.
    private function fileDriver(): string
    {
        foreach ($this->fileWritingChannels() as $name) {
            $driver = $this->config->get('logging.channels.'.$name.'.driver');

            if (is_string($driver) && in_array($driver, self::FILE_DRIVERS, true)) {
                return $driver;
            }
        }

        return 'daily';
    }

    /**
     * @return list<string>
     */
    private function fileWritingChannels(): array
    {
        $default = $this->config->get('logging.default');
        $queue = is_string($default) && $default !== '' ? [$default] : [];

        $seen = [];
        $out = [];

        // A stack may name another stack, and a stack may name itself: the
        // visited set is what keeps a self-referential channel from spinning.
        for ($depth = 0; $queue !== [] && $depth < self::MAX_STACK_DEPTH; $depth++) {
            $next = [];

            foreach ($queue as $name) {
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;

                $stacked = $this->stackedChannels($name);

                if ($stacked === null) {
                    $out[] = $name;

                    continue;
                }

                $next = [...$next, ...$stacked];
            }

            $queue = $next;
        }

        return $out;
    }

    // Null rather than an empty list for a channel that is not a stack: a
    // stack naming nothing usable and a plain channel are different answers,
    // and only the second is a channel that writes.
    /**
     * @return list<string>|null
     */
    private function stackedChannels(string $name): ?array
    {
        if ($this->config->get('logging.channels.'.$name.'.driver') !== self::STACK_DRIVER) {
            return null;
        }

        $nested = $this->config->get('logging.channels.'.$name.'.channels');

        $children = [];
        foreach (is_array($nested) ? $nested : [] as $child) {
            if (is_string($child) && $child !== '') {
                $children[] = $child;
            }
        }

        return $children;
    }
}
