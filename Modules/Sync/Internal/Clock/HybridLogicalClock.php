<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Clock;

final class HybridLogicalClock
{
    // Not readonly — $l and $c are mutable state. Bind as TRANSIENT in the
    // service provider (never singleton) to avoid leaking clock state across
    // unrelated callers.

    /** @var int Max physical time seen (wall-clock ms). */
    private int $l = 0;

    /** @var int Logical counter; resets to 0 when advances. */
    private int $c = 0;

    // Advances $l to max(wall_ms, $l). If $l did not change, increments $c;
    // otherwise resets $c to 0.
    /**
     * @return array{int, int} [$l, $c] to embed in the op-log entry.
     */
    public function tick(): array
    {
        $pt = (int) (microtime(true) * 1000);

        if ($pt > $this->l) {
            $this->l = $pt;
            $this->c = 0;
        } else {
            $this->c++;
        }

        return [$this->l, $this->c];
    }

    // Applies the four-branch Kulkarni-Demirbas update rule: all three equal ->
    // c = max(c_local, c_msg) + 1; l_new === l_local only -> c_local++;
    // l_new === l_msg only -> c = c_msg + 1; wall-clock was highest -> c = 0.
    /**
     * @param  int  $lMsg  physical_ms from the remote entry
     * @param  int  $cMsg  counter from the remote entry
     * @return array{int, int} Updated [$l, $c].
     */
    public function receive(int $lMsg, int $cMsg): array
    {
        $pt = (int) (microtime(true) * 1000);
        $lNew = max($pt, $this->l, $lMsg);

        if ($lNew === $this->l && $lNew === $lMsg) {
            $this->c = max($this->c, $cMsg) + 1;
        } elseif ($lNew === $this->l) {
            $this->c++;
        } elseif ($lNew === $lMsg) {
            $this->c = $cMsg + 1;
        } else {
            $this->c = 0;
        }

        $this->l = $lNew;

        return [$this->l, $this->c];
    }

    // Order: l (physical ms) first, then c (counter), then strcmp($d1, $d2) as
    // the final tie-breaker, guaranteeing a deterministic total order across
    // all devices — required for non-determinism-free LWW merge.
    public static function compare(
        int $l1,
        int $c1,
        string $d1,
        int $l2,
        int $c2,
        string $d2,
    ): int {
        if ($l1 !== $l2) {
            return $l1 <=> $l2;
        }

        if ($c1 !== $c2) {
            return $c1 <=> $c2;
        }

        return strcmp($d1, $d2);
    }
}
