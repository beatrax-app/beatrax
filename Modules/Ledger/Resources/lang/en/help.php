<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Reconciling is checking Beatrax against your bank’s own figure. The cleared balance is this account’s opening balance plus every row you have ticked as cleared up to the statement date, and the difference is your statement’s figure minus that. Tick or untick rows on the transactions list until it reaches zero — this screen never invents a balancing entry to close a gap. “:complete” then locks the rows it covers: a locked row cannot be edited, split or deleted until you unlock it again from its own page.',
];
