<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/calendar/architecture.md#entries-the-balance-line-does-not-reach */
    'balance_column' => 'Which accounts the projected daily balance is built from. The column beside it, “:entries”, decides something different — whether that account’s payments are drawn on the grid at all — so an account can be shown without counting, or counted without being shown. Where the two disagree the day says so under its balance, rather than letting a figure be quietly built from less than you expect.',
];
