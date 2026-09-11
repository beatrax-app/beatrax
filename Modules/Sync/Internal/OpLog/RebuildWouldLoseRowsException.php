<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use RuntimeException;

// A rebuild deletes every replayable row before it replays the log over them,
// so a create the replay cannot apply is a row that was here and now is not.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class RebuildWouldLoseRowsException extends RuntimeException
{
    /**
     * @param  array<string, int>  $missingByTable  rows deleted and not restored
     * @param  array<string, int>  $quarantinedByReason  refusals this replay recorded
     */
    public function __construct(
        public readonly array $missingByTable,
        public readonly array $quarantinedByReason,
    ) {
        parent::__construct('The replay did not restore every row the rebuild deleted.');
    }
}
