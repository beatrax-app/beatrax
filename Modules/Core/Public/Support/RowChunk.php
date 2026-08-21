<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// How many rows a bulk pass over one of this app's own tables holds at once —
// reindex, encryption migration, batch insert, retention prune. It bounds peak
// memory and transaction length, never correctness, so it is the default each
// pass starts from rather than a number every pass is held to.
final class RowChunk
{
    public const int DEFAULT_SIZE = 500;
}
