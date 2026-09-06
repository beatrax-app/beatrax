<?php

declare(strict_types=1);

namespace Modules\Notifications\Tests\Support;

use Illuminate\Bus\Dispatcher;
use RuntimeException;

// A pass fails the way a pass really fails: the job it dispatches in-process
// throws. Reached by handing this to the dispatch seam of one module, so the
// other pass's dispatches are untouched and "the second one still ran" is a
// difference between two passes rather than a claim about one.
final class BusThatRefusesEveryJob extends Dispatcher
{
    public function __construct() {}

    public function dispatchSync($command, $handler = null): mixed
    {
        throw new RuntimeException('the job this pass dispatches could not finish');
    }

    public function dispatch($command): mixed
    {
        throw new RuntimeException('the job this pass dispatches could not finish');
    }
}
