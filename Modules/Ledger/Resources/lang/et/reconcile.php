<?php

declare(strict_types=1);

return [
    'page_title' => 'Kooskõlastamine',
    'heading' => 'Kooskõlastamine',
    'intro' => 'Kinnita konto väljavõtte jääk oma laekunud tehingute vastu. Kui need kattuvad, lõpeta kooskõlastus, et need read lukustada.',

    'account' => 'Konto',
    'choose_account' => 'Vali konto…',
    'statement_date' => 'Väljavõtte kuupäev',
    'statement_balance' => 'Väljavõtte jääk (€)',
    'balance_help' => 'Täidetud võimaluse korral sinu viimasest imporditud väljavõttest — võlgnetava raha puhul negatiivne, mõlemal juhul muudetav.',

    'cleared_balance' => 'Laekunud jääk',
    'statement_target' => 'Väljavõtte siht',
    'difference' => 'Vahe',

    'pill' => [
        'choose_account' => 'vali konto',
        'enter_balance' => 'sisesta väljavõtte jääk',
        'matched' => 'kattub — :amount',
        'discrepancy' => 'lahknevus — :amount',
    ],

    'mismatch_html' => 'Väljavõtte jääk ei kattu veel sinu laekunud jäägiga. Muuda laekunud ridade olekut <a href=":url" class="underline">tehingute loendis</a> või kohanda sisestatud jääki, kuni vahe on null — see voog ei loo kunagi tasakaalustavat kirjet.',

    'check' => 'Kontrolli',
    'complete' => 'Lõpeta kooskõlastus',

    'errors' => [
        'choose_account' => 'Vali kõigepealt konto.',
        'invalid_balance_date' => 'Sisesta kehtiv väljavõtte jääk ja kuupäev.',
        'mismatch' => 'Väljavõtte jääk ei kattu veel laekunud jäägiga — kohanda laekunud ridu või sisestatud jääki, kuni vahe on null.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Selle väljavõtte kuupäeva jaoks pole midagi lukustada.',
        'complete' => 'Kooskõlastus on lõpetatud — lukustati :count rida.',
    ],
];
