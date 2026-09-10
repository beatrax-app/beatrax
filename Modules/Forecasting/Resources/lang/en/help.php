<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Which account a projected payment is counted against. Some payments never leave the account they appear to leave: a card purchase is settled later by one charge on your bank account, and a wallet payment is funded by a transfer into it. Turn this on and each of those is counted against the account that actually pays it, so an account\'s line shows the money that will really move through it rather than the money that merely passed by.',
];
