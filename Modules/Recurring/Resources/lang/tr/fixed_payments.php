<?php

declare(strict_types=1);

return [
    'heading' => 'Sabit aylık ödemeler',

    'summary' => [
        'expenses' => 'giderler',
        'income' => 'gelir',
        'net' => 'net',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Gider',
        'income' => 'Gelir',
    ],

    'filter_aria' => 'Sabit ödemeleri filtrele',
    'filter_all' => 'Tüm seriler',
    'filter_this_month' => 'Yalnızca bu ay',

    'empty_this_month' => 'Bu ay vadesi gelen düzenli seri yok.',
    'empty_all' => 'Henüz onaylanmış düzenli seri yok.',

    'chain' => 'zincir',
    'chain_aria' => 'Zincir üzerinden finanse ediliyor',
    'per_month_suffix' => '/ay',

    'view_all' => 'Tümünü görüntüle →',
];
