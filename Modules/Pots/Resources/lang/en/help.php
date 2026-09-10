<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'A pot sets money aside inside an account rather than moving it out, so the balance at your bank does not change when you fund one or take from one. Each account shows three figures: “:real” is what the bank holds, “:allocated” is what your pots between them have claimed, and “:unallocated” is what is left over — the only part still free to spend. When that last figure falls below zero the pots claim more than the account holds, and every pot balance is overstated until you take some back.',
];
