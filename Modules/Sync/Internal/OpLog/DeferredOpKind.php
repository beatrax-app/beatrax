<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

// What the drain has to do with a coordinate, which is not the same question
// as what op_type the op ends up carrying: Increment and Set both become a
// Set op, and only this enum remembers that one of them owes a delta.
enum DeferredOpKind: string
{
    case Create = 'create';

    case Set = 'set';

    case Increment = 'increment';

    case Delete = 'delete';
}
