<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'One payment often pays for several others: a card settlement on your bank account covers a month of card purchases, and a bank withdrawal funds a wallet payment made days earlier. A chain records which charge paid for what, so a purchase on one statement can be traced back to the money that actually left your account. Beatrax links the ones it is certain of by itself and leaves the rest in the review queue for you. Confirm the same kind of link a few times and it stops asking about that kind.',
];
