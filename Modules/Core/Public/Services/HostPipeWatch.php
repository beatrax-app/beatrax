<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

// Whether the process that spawned this one is still there, told by the stdin
// pipe it holds open. A force quit runs no shutdown hook, and the bundled PHP
// has neither ext-pcntl to trap a signal nor ext-posix to poll a parent id —
// the pipe is the only notice a child gets that it has been orphaned.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class HostPipeWatch
{
    // fstat()'s mode field packs the file type into its top bits; these are
    // POSIX's S_IFMT (the mask) and S_IFIFO (the pipe type).
    private const int FILE_TYPE_MASK = 0170000;

    private const int FILE_TYPE_PIPE = 0010000;

    // A terminal or /dev/null is not a host handle. Reading one as such would
    // make a hand-run `php artisan queue:work` exit the instant it started.
    public static function isHeldByHost(): bool
    {
        $stat = @fstat(STDIN);

        if ($stat === false) {
            return false;
        }

        return ($stat['mode'] & self::FILE_TYPE_MASK) === self::FILE_TYPE_PIPE;
    }

    // select() before reading, so an open-but-quiet pipe is never mistaken for
    // a dead one and the read below cannot block. feof() alone would answer
    // "not yet" forever: it reports EOF only once a read has hit it.
    public static function hostHasGone(): bool
    {
        if (! self::isHeldByHost()) {
            return false;
        }

        $read = [STDIN];
        $write = null;
        $except = null;

        if (@stream_select($read, $write, $except, 0) !== 1) {
            return false;
        }

        // Readable means bytes or a closed peer. Nothing writes to these
        // processes, so a non-empty read is discarded rather than misread.
        $byte = @fread(STDIN, 1);

        return $byte === '' || $byte === false;
    }
}
