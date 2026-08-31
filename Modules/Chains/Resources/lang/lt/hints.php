<?php

declare(strict_types=1);

return [
    'page_title' => 'Grandinių užuominos',
    'heading' => 'Užuominos',
    'back_to_review' => '← Atgal į peržiūros eilę',
    'subtitle' => 'Kandidatai, kuriuos derintuvas pateikė be atitinkamos poros. Atsiskaitymo užuomina dingsta pati, kai atkeliauja trūkstamos išlaidos; likusios lieka, kol jų čia neatmesite.',

    'empty_heading' => 'Rūšiuoti nėra jokių užuominų',
    'empty_body' => 'Kai derintuvas aptiks grandinę, kurios nepavyko išspręsti automatiškai, ji atsiras čia.',

    'no_counterparty' => '(kitos šalies nėra)',
    'unknown_account' => '(nežinoma sąskaita)',

    'dismiss' => 'Slėpti',
    'dismiss_aria' => 'Slėpti užuominą :id',
    'dismissed' => 'Užuomina paslėpta.',

    'kind' => [
        'ics_bulk_settle' => 'Bendras iDEAL atsiskaitymas (už leistinos paklaidos ribų)',
        'funded_by_card_hint' => 'Finansuota kortele (užuomina)',
        'refund_of_hint' => 'Grąžinimas (užuomina)',
    ],

    'evidence' => [
        'tolerance' => 'Leistina paklaida: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'fiksuotos paklaidos ribose',
            'percent_2' => 'procentinės paklaidos ribose',
            'exceeded' => 'už paklaidos ribų',
            'refund_after_close' => 'grąžinimas po uždarymo',
        ],
        'delta_overpaid' => 'Permokėta :amount',
        'delta_underpaid' => 'Trūksta :amount',
        'delta_balanced' => 'Sutampa tiksliai',
        'covered' => 'Padengtos operacijos: :count',
        'statement' => 'Kortelės išrašas Nr. :id',
        'card_last4' => 'Kortelė, kurios pabaiga :last4',
        'original_reference' => 'Pradinė užsakymo nuoroda: :reference',
    ],
];
