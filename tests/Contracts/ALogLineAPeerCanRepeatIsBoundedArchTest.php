<?php

declare(strict_types=1);

use Tests\Contracts\Support\LoopBoundLogLines;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-log-line-a-peer-can-repeat
 */

// The iteration count in Modules/Sync/Internal/Transport is chosen by the peer:
// it is however many entries arrived in one frame. A line written per entry is
// therefore a line a peer can write six thousand times, which is what twice
// happened in SyncSession::receiveOps() before it counted and reported once.

/**
 * @return list<string>
 */
function loopBoundLogSources(): array
{
    $root = base_path('Modules/Sync/Internal/Transport');

    if (! is_dir($root)) {
        return [];
    }

    $files = [];

    /** @var SplFileInfo $entry */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'php') {
            $files[] = $entry->getPathname();
        }
    }

    sort($files);

    return $files;
}

it('writes no log line the peer on the other end can ask for again', function (): void {
    $sources = loopBoundLogSources();
    $offenders = [];
    $calls = 0;

    foreach ($sources as $file) {
        $result = LoopBoundLogLines::scan((string) file_get_contents($file));
        $calls += $result['calls'];

        foreach ($result['offenders'] as $offender) {
            $offenders[] = sprintf(
                '%s:%d writes ->%s() once per turn of a loop the peer sizes',
                str_replace(base_path().'/', '', $file),
                $offender['line'],
                $offender['name'],
            );
        }
    }

    expect($offenders)->toBe([], implode("\n", $offenders));

    // The walk read something. A scope that moves under this rule reports no
    // offenders for the same reason a clean tree does, and only this separates
    // them -- it does not, and cannot, catch a reader blind to one spelling.
    expect($sources)->not->toBeEmpty()
        ->and($calls)->toBeGreaterThan(10);
});

it('reads a flood, a nullsafe flood, a line that leaves, and the counted report', function (): void {
    $flood = LoopBoundLogLines::scan(<<<'PHP'
        <?php
        foreach ($entries as $entry) {
            $this->logger->warning('refused one', ['id' => $entry->id]);

            continue;
        }
        PHP);

    $nullsafe = LoopBoundLogLines::scan(<<<'PHP'
        <?php
        foreach ($entries as $entry) {
            $this->logger?->error('held one', ['id' => $entry->id]);
        }
        PHP);

    // The pinned shape in SyncWebSocketHandler: the line is written and control
    // leaves, so the loop cannot come back to it however long the peer talks.
    $leaves = LoopBoundLogLines::scan(<<<'PHP'
        <?php
        while ($message = $this->read()) {
            if ($this->peerWasRevoked($session)) {
                $this->logger->info('peer revoked mid-session');

                break;
            }
        }
        PHP);

    // What receiveOps() does now, and the shape this rule exists to keep.
    $counted = LoopBoundLogLines::scan(<<<'PHP'
        <?php
        foreach ($entries as $entry) {
            $refused[$entry->deviceId] = ($refused[$entry->deviceId] ?? 0) + 1;

            continue;
        }

        if ($refused !== []) {
            $this->logger?->error('refused some', ['refused' => array_sum($refused)]);
        }
        PHP);

    expect($flood['offenders'])->toHaveCount(1)
        ->and($nullsafe['offenders'])->toHaveCount(1)
        ->and($leaves['offenders'])->toBe([])
        ->and($counted['offenders'])->toBe([]);

    // Every one of the four was recognised as a log call to begin with, so a
    // clean answer above is a judgement and not a reader that saw nothing.
    expect([$flood['calls'], $nullsafe['calls'], $leaves['calls'], $counted['calls']])
        ->toBe([1, 1, 1, 1]);
});
