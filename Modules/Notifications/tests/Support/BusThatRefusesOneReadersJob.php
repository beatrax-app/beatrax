<?php

declare(strict_types=1);

namespace Modules\Notifications\Tests\Support;

use Illuminate\Bus\Dispatcher;
use RuntimeException;

// The sibling of BusThatRefusesEveryJob, for the question that one cannot ask:
// a pass fails for ONE reader and the readers after them still have to run. The
// queue table is the same single-writer SQLite file everything else is holding,
// so a busy one surfacing out of a single user's dispatch is the real shape.
final class BusThatRefusesOneReadersJob extends Dispatcher
{
    /** @var list<int> */
    public array $accepted = [];

    public function __construct(private readonly int $refuseUserId) {}

    public function dispatch($command): mixed
    {
        // Read off the job rather than matched against its class: both passes
        // queue a job owned by the module that raises it, and naming either
        // here would be this module's test reaching into a neighbour's.
        $userId = is_object($command) && property_exists($command, 'userId') && is_int($command->userId)
            ? $command->userId
            : null;

        if ($userId === $this->refuseUserId) {
            throw new RuntimeException('SQLITE_BUSY: the queue table was locked');
        }

        if ($userId !== null) {
            $this->accepted[] = $userId;
        }

        return null;
    }

    public function dispatchSync($command, $handler = null): mixed
    {
        return $this->dispatch($command);
    }
}
