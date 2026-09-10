<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Reconciling is checking Beatrax against your bank’s own figure. The cleared balance is this account’s opening balance plus every row you have ticked as cleared up to the statement date, and the difference is your statement’s figure minus that. Tick or untick rows on the transactions list until it reaches zero — this screen never invents a balancing entry to close a gap. “:complete” then locks the rows it covers: a locked row cannot be edited, split or deleted until you unlock it again from its own page.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Where a row stands against your bank statement. “:uncleared” means Beatrax has the transaction and you have not matched it to one yet; tap the pill to make it “:cleared”, and it is those ticks the reconcile screen adds up. “:reconciled” is not a pill you can tap — completing a reconcile sets it and locks the row, so its category, note, split and tax tags stay as they are until you unlock it again.',
];
