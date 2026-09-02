<?php

declare(strict_types=1);

return [
    'heading' => 'Kiinteät kuukausimaksut',

    'summary' => [
        'expenses' => 'menot',
        'income' => 'tulot',
        'net' => 'netto',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Meno',
        'income' => 'Tulo',
    ],

    'filter_aria' => 'Suodata kiinteitä maksuja',
    'filter_all' => 'Kaikki sarjat',
    'filter_this_month' => 'Vain tämä kuukausi',

    'empty_this_month' => 'Yksikään toistuva sarja ei eräänny tässä kuussa.',
    'empty_all' => 'Ei vielä hyväksyttyjä toistuvia sarjoja.',

    'chain' => 'ketju',
    'chain_aria' => 'Rahoitettu ketjun kautta',
    'per_month_suffix' => '/kk',

    'view_all' => 'Näytä kaikki →',
];
