<?php

declare(strict_types=1);

return [
    'page_title' => 'Kontrolna tabla',
    'subtitle' => 'Ovaj period ukratko.',

    'previous_period' => 'Prethodni period',
    'today' => 'Danas',
    'next_period' => 'Sledeći period',

    'totals_aria' => 'Ukupno u ovom periodu',
    'totals_aria_currency' => 'Ukupno u ovom periodu — :currency',
    'in' => 'Priliv',
    'out' => 'Odliv',
    'net' => 'Neto',

    'status_tiles_aria' => 'Statusne pločice',
    // i18n-review: sr · email_scan_health — agreement is fixed, the noun is not:
    // "sandučad" is a collective, so its genitive plural is contested against
    // "sandučića", and badge.inboxes calls the same thing "prijemno sanduče".
    'email_scan_health' => 'Stanje skeniranja e-pošte — :count povezano sanduče|Stanje skeniranja e-pošte — :count povezana sandučeta|Stanje skeniranja e-pošte — :count povezanih sandučadi',

    'top_spending' => 'Najveći troškovi',
    'no_expenses' => 'Još nema kategorizovanih troškova.',

    'recent_transactions' => 'Nedavne transakcije',
    'view_all' => 'Prikaži sve',
    'nothing_period' => 'Ništa za ovaj period.',
    'th_date' => 'Datum',
    'th_counterparty' => 'Druga strana',
    'th_category' => 'Kategorija',
    'th_amount' => 'Iznos',
    'uncategorized' => 'Bez kategorije',

    'reauth' => [
        'title' => 'Prijemno sanduče treba ponovo povezati.',
        'body' => 'Jedno ili više sandučadi je odjavljeno — Beatrax ne može da ih skenira dok ih ponovo ne povežeš.',
        'link' => 'Idi na sandučad',
        'dismiss' => 'Odbaci',
    ],

    'failed_chain' => [
        'title' => 'Razrešavanje lanaca nije uspelo.',
        'body' => 'Jedan ili više zadataka razrešavanja lanaca naišao je na grešku.',
        'link' => 'Otvori inspektor reda čekanja',
    ],
];
