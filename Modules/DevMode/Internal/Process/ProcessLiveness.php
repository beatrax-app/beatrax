<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

// The shell fallback exists because the shipped NativePHP Mac PHP binary omits
// ext-posix. It costs ~1ms per check against microseconds for posix_kill.
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
