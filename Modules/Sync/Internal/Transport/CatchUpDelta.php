<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Closure;
use Countable;
use Generator;
use IteratorAggregate;

// The owed delta as a count and a stream rather than a list. The protocol
// declares how many frames follow before it sends them, and building all of
// them to answer that held the peer's entire history in memory at once.
/**
 * @implements IteratorAggregate<int, string>
 *
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md#the-delta-is-counted-then-streamed
 */
final readonly class CatchUpDelta implements Countable, IteratorAggregate
{
    /**
     * @param  int<0, max>  $frameCount  Frames the stream yields — the number the wire declares.
     * @param  Closure(): Generator<int, string>  $frames  Re-runnable; each call re-reads the same bounded rows.
     */
    public function __construct(private int $frameCount, private Closure $frames) {}

    public function count(): int
    {
        return $this->frameCount;
    }

    /**
     * @return Generator<int, string>
     */
    public function getIterator(): Generator
    {
        return ($this->frames)();
    }
}
