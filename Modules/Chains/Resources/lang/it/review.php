<?php

declare(strict_types=1);

return [
    'page_title' => 'Rivedi le catene',
    'heading' => 'Rivedi le catene',
    'hint' => ':count suggerimento|:count suggerimenti',
    'subtitle' => 'Conferma o rifiuta i collegamenti candidati che il risolutore delle catene non ha potuto confermare automaticamente.',

    'empty_heading' => 'Niente da rivedere',
    'empty_body' => 'Ogni anello della catena è confermato o rifiutato. I nuovi candidati compariranno qui man mano che arrivano le importazioni.',

    'auto_confirm_nudge' => 'Ancora una conferma e i collegamenti simili si confermeranno da soli.',

    'confirm' => 'Conferma',
    'reject' => 'Rifiuta',
    'confirm_aria' => "Conferma l'anello della catena :id",
    'reject_aria' => "Rifiuta l'anello della catena :id",
    'show_more' => 'Mostra altri',

    'kind' => [
        'paypal_funding' => 'Finanziamento PayPal',
        'ics_bulk_settle' => 'Regolamento iDEAL cumulativo',
    ],

    'errors' => [
        'confirm_hint' => 'Questo candidato è un suggerimento — aprilo e collega la transazione corrispondente prima di confermare.',
        'reject_hint' => 'Questo candidato è un suggerimento — aprilo e collega la transazione corrispondente prima di rifiutare.',
    ],
];
