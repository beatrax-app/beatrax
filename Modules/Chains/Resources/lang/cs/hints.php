<?php

declare(strict_types=1);

return [
    'page_title' => 'Nápovědy k řetězcům',
    'heading' => 'Nápovědy',
    'back_to_review' => '← Zpět do fronty ke kontrole',
    'subtitle' => 'Návrhy, které párovač vydal bez odpovídajícího protějšku. Nápověda k vyrovnání zmizí sama, jakmile dorazí chybějící platby; ostatní zůstanou, dokud je tu neodmítnete.',

    'empty_heading' => 'Žádné nápovědy k roztřídění',
    'empty_body' => 'Když párovací mechanismus najde řetězec, který nedokázal vyřešit automaticky, objeví se tady.',

    'no_counterparty' => '(bez protistrany)',
    'unknown_account' => '(neznámý účet)',

    'dismiss' => 'Zamítnout',
    'dismiss_aria' => 'Zamítnout nápovědu :id',
    'dismissed' => 'Nápověda zamítnuta.',

    'kind' => [
        'ics_bulk_settle' => 'Hromadné vyrovnání iDEAL (mimo toleranci)',
        'funded_by_card_hint' => 'Financováno kartou (nápověda)',
        'refund_of_hint' => 'Vrácení peněz (nápověda)',
    ],

    'evidence' => [
        'tolerance' => 'Tolerance: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'v pevné toleranci',
            'percent_2' => 'v procentní toleranci',
            'exceeded' => 'mimo toleranci',
            'refund_after_close' => 'vrácení po uzavření',
        ],
        'delta_overpaid' => 'Přeplatek :amount',
        'delta_underpaid' => 'Chybí :amount',
        'delta_balanced' => 'Sedí přesně',
        'covered' => 'Pokryté transakce: :count',
        'statement' => 'Výpis karty č. :id',
        'card_last4' => 'Karta končící :last4',
        'original_reference' => 'Původní reference objednávky: :reference',
    ],
];
