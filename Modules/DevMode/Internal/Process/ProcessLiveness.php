<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

/**
 * Portable "is this PID still running?" check.
 *
 * Prefers `posix_kill($pid, 0)` — a kernel-level liveness probe that
 * costs microseconds and never sends a signal — when the `posix`
 * extension is loaded. Falls back to `kill -0` via `shell_exec` on
 * runtimes that omit ext-posix; the shipped NativePHP Mac PHP binary
 * does NOT ship posix or pcntl, and the SSE controller's tail loop
 * crashed on the missing function before this helper landed.
 *
 * The fallback is ~1 ms per check (fork + exec); at the controller's
 * 6.7 ticks/s tail cadence that adds ~6 ms wall-clock per second of
 * streaming, which is acceptable for a developer-only surface.
 *
 * The `kill -0` shell builtin returns exit-status 0 when the target
 * PID exists AND is signalable by the caller; non-zero otherwise.
 * stderr is silenced so a "No such process" line never leaks into the
 * SSE frame budget.
 */
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
