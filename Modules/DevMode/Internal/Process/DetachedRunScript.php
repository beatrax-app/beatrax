<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

// The shell wrapper a detached run is launched through. Its whole job is to
// hand the caller the child's pid and then get out of the way, leaving a
// watcher behind that outlives this PHP process to record the exit code.
final class DetachedRunScript
{
    public static function for(string $invocation, string $outPath): string
    {
        // `< /dev/null` stops the child holding the request's stdin open.
        // setsid would detach more cleanly but is absent from macOS' default
        // toolchain, so plain `&` it is.
        $redirect = '> '.escapeshellarg($outPath).' 2>&1';
        $detach = $invocation.' '.$redirect.' < /dev/null &';

        // The subshell is the child's PARENT, which is the only process that
        // can be told its exit code. `$!` is still the artisan pid, so cancel
        // signals and liveness probes reach the command itself.
        $watch = '( '.$detach.' p=$!; echo $p; exec 1>&-; '
            .'wait $p; echo $? > '.escapeshellarg(RunExitCodeFile::pathFor($outPath)).' ) &';

        // Load-bearing, not decoration: Symfony stops reading when the process
        // it started exits, not when the pipe reaches EOF, so echoing straight
        // from the backgrounded watcher makes the pid a race. Assigning it
        // blocks bash until the pid has been read.
        return 'bash -c '.escapeshellarg('pid=$( '.$watch.' ); echo "$pid"');
    }
}
