<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

// Whether a replay may treat the entries it was handed as the whole story for
// the rows they name. A strategy resolves over the set it is given, so a
// frame-sized slice resolves against a frame-sized truth.
/**
 * @link ../../../../.docs/features/sync/op-log-merge-rules.md#a-batch-is-not-the-set-a-strategy-resolves-over
 */
enum RowHistoryPolicy
{
    // A transport frame, a live-loop message, a local write: any slice whose
    // boundary is arbitrary. The durable log is read back for every row the
    // slice names before a single strategy runs.
    case FromDurableLog;

    // A full rebuild, or the quarantine drain's per-row fetch — the caller
    // already loaded every entry for the rows it names, so reading them again
    // would only re-fetch what it holds.
    case AsGiven;
}
