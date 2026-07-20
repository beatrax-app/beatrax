<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

// Prefers posix_kill($pid, 0) (a kernel-level liveness probe that never
// sends a signal) when the posix extension is loaded; falls back to
// `kill -0` via shell_exec on runtimes that omit ext-posix (e.g. the
// shipped NativePHP Mac PHP binary), at ~1ms per check instead of ~us.
final class ProcessLiveness
{
    public function isAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        $out = @shell_exec(sprintf('kill -0 %d 2>/dev/null; echo $?', $pid));

        return is_string($out) && trim($out) === '0';
    }
}
