<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Kalenteri',
        'subtitle' => 'Tulevat maksut ja ennustettu päiväsaldo.',
    ],

    'summary' => [
        'computing' => 'Ennustetta päivitetään…',
        'risk' => 'Saldo painuu alle nollan :date.|Saldo painuu alle nollan :count päivänä — ensimmäinen: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Edellinen kuukausi',
        'next_month' => 'Seuraava kuukausi',
        'accounts' => 'Tilit',
        'popover_aria' => 'Tilien näyttöasetukset',
        'no_accounts' => 'Tilejä ei löytynyt.',
        'col_account' => 'Tili',
        'col_entries' => 'Merkinnät',
        'col_balance' => 'Saldo',
        'show_entries_aria' => 'Näytä tilin :name merkinnät',
        'count_balance_aria' => 'Laske tili :name mukaan saldoon',
    ],

    'empty' => [
        'heading' => 'Ei tulevia maksuja',
        'body' => 'Yhdistä tili tai hyväksy toistuva sarja, niin näet ennustetut maksusi kalenterissa.',
        'review' => 'Tarkista toistuvat maksut →',
    ],

    'weekdays' => [
        'mon' => 'ma',
        'tue' => 'ti',
        'wed' => 'ke',
        'thu' => 'to',
        'fri' => 'pe',
        'sat' => 'la',
        'sun' => 'su',
    ],

    'grid' => [
        'aria' => 'Kalenteri :month',
    ],

    'cell' => [
        'entry' => 'merkintä|merkintää',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', ennustettu saldo miinus :amount',
        'aria_balance_positive' => ', ennustettu saldo :amount',
        'overflow' => '+:count muuta',
        'paid' => 'Maksettu',
        'missed' => 'Odotettu — ei löytynyt',
    ],

    'panel' => [
        'aria' => 'Päivän tietopaneeli',
        'close' => 'Sulje päivänäkymä',
        'start_of_day' => 'Päivän alku',
        'no_payments' => 'Ei maksuja tänä päivänä.',
        'date_approximate' => '~ päivä on arvio',
        'series' => '↗ sarja',
        'counterparty' => '↗ vastapuoli',
        'end_of_day' => 'Päivän loppu',
    ],
];
