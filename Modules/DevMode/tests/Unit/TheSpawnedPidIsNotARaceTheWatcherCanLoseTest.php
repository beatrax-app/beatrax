<?php

declare(strict_types=1);

use Modules\DevMode\Internal\Process\DetachedRunScript;
use Modules\DevMode\Internal\Process\RunExitCodeFile;
use Symfony\Component\Process\Process;

it('reads the pid back even when the watcher is slow to emit it', function (): void {
    $outPath = sys_get_temp_dir().'/detached-run-'.bin2hex(random_bytes(8)).'.out';
    $exitPath = RunExitCodeFile::pathFor($outPath);

    $script = DetachedRunScript::for('/bin/sh -c '.escapeshellarg('exit 3'), $outPath);

    // Widening the window the watcher has to win, because it usually wins it
    // by luck. Symfony stops reading when the process it started exits rather
    // than when the pipe reaches EOF, so an unassigned `echo $p` loses the pid
    // outright here, which is what this test exists to keep out.
    $slowed = str_replace('echo $p;', 'sleep 0.5; echo $p;', $script, $applied);
    expect($applied)->toBe(1);

    $process = Process::fromShellCommandline($slowed, sys_get_temp_dir());
    $process->setTimeout(10.0);
    $process->run();

    expect($process->isSuccessful())->toBeTrue();
    expect(trim($process->getOutput()))->toMatch('/^\d+$/');

    // Waiting on the value rather than on the file: `echo $? > path` creates
    // the sidecar and writes the digits into it as two steps, so a wait that
    // ends at is_file() can read it empty -- which read() answers null for,
    // and null is what a dropped pid answers too.
    $deadline = microtime(true) + 10.0;
    while (RunExitCodeFile::read($outPath) === null && microtime(true) < $deadline) {
        usleep(20_000);
    }

    // A dropped pid also leaves bash's unflushed stdout to spill into the next
    // thing it opens, which is this file, turning a clean run into no answer.
    expect(RunExitCodeFile::read($outPath))->toBe(3);

    @unlink($outPath);
    @unlink($exitPath);
});
