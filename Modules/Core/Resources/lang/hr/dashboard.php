<?php

declare(strict_types=1);

return [
    'page_title' => 'Nadzorna ploča',
    'subtitle' => 'Ovo razdoblje ukratko.',

    'previous_period' => 'Prethodno razdoblje',
    'today' => 'Danas',
    'next_period' => 'Sljedeće razdoblje',

    'totals_aria' => 'Ukupno u ovom razdoblju',
    'totals_aria_currency' => 'Ukupno u ovom razdoblju — :currency',
    'in' => 'Priljev',
    'out' => 'Odljev',
    'net' => 'Neto',

    'status_tiles_aria' => 'Statusne pločice',
    // i18n-review: hr · email_scan_health — agreement is fixed, the noun is not:
    // this says "pretinac" where core sidebar badge.inboxes says "pristigla
    // pošta" for the same thing. One of the two is what Croatian readers use.
    'email_scan_health' => 'Stanje skeniranja e-pošte — :count povezan pretinac|Stanje skeniranja e-pošte — :count povezana pretinca|Stanje skeniranja e-pošte — :count povezanih pretinaca',

    'top_spending' => 'Najveći troškovi',
    'no_expenses' => 'Još nema kategoriziranih troškova.',

    'recent_transactions' => 'Nedavne transakcije',
    'view_all' => 'Prikaži sve',
    'nothing_period' => 'Ništa za ovo razdoblje.',
    'th_date' => 'Datum',
    'th_counterparty' => 'Protustranka',
    'th_category' => 'Kategorija',
    'th_amount' => 'Iznos',
    'uncategorized' => 'Bez kategorije',

    'reauth' => [
        'title' => 'Ulazni pretinac treba ponovno povezati.',
        'body' => 'Jedan ili više pretinaca odjavljeno je — Beatrax ih ne može skenirati dok ih ponovno ne povežeš.',
        'link' => 'Idi na pretince',
        'dismiss' => 'Odbaci',
    ],

    'failed_chain' => [
        'title' => 'Razrješavanje lanaca nije uspjelo.',
        'body' => 'Jedan ili više zadataka razrješavanja lanaca naišao je na pogrešku.',
        'link' => 'Otvori inspektor reda čekanja',
    ],
];
