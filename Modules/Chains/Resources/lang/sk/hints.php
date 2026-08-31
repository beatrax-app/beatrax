<?php

declare(strict_types=1);

return [
    'page_title' => 'Tipy k reťazcom',
    'heading' => 'Tipy',
    'back_to_review' => '← Späť do frontu na kontrolu',
    'subtitle' => 'Kandidáti, ktorých párovač vydal bez zodpovedajúceho náprotivku. Nápoveda k vyrovnaniu zmizne sama, len čo dorazia chýbajúce platby; ostatné zostanú, kým ich tu nezamietnete.',

    'empty_heading' => 'Žiadne tipy na triedenie',
    'empty_body' => 'Keď párovací mechanizmus nájde reťazec, ktorý nedokáže vyriešiť automaticky, objaví sa tu.',

    'no_counterparty' => '(žiadna protistrana)',
    'unknown_account' => '(neznámy účet)',

    'dismiss' => 'Zamietnuť',
    'dismiss_aria' => 'Zamietnuť tip :id',
    'dismissed' => 'Tip zamietnutý.',

    'kind' => [
        'ics_bulk_settle' => 'Hromadné zúčtovanie iDEAL (mimo tolerancie)',
        'funded_by_card_hint' => 'Financované kartou (tip)',
        'refund_of_hint' => 'Vrátenie peňazí (tip)',
    ],

    'evidence' => [
        'tolerance' => 'Tolerancia: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'v pevnej tolerancii',
            'percent_2' => 'v percentuálnej tolerancii',
            'exceeded' => 'mimo tolerancie',
            'refund_after_close' => 'vrátenie po uzavretí',
        ],
        'delta_overpaid' => 'Preplatok :amount',
        'delta_underpaid' => 'Chýba :amount',
        'delta_balanced' => 'Sedí presne',
        'covered' => 'Pokryté transakcie: :count',
        'statement' => 'Výpis karty č. :id',
        'card_last4' => 'Karta končiaca :last4',
        'original_reference' => 'Pôvodná referencia objednávky: :reference',
    ],
];
