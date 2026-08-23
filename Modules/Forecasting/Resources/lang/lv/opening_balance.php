<?php

declare(strict_types=1);

return [
    'help_paypal' => 'PayPal eksportos nav atlikuma rindu, tāpēc iestatiet to manuāli.',
    'help_asn' => 'Automātiski noteikts pēc jaunākā konta izraksta. Mainiet tikai tad, ja zināt, ka faktiskais atlikums atšķiras.',
    'help_default' => 'Mainiet tikai tad, ja zināt, ka pašreizējais faktiskais atlikums atšķiras no tā, ko aprēķina Beatrax.',

    'legend' => 'Prognozes sākuma atlikums kontam :name',
    'opening_label' => 'Sākuma atlikums',
    'opening_placeholder' => 'piem. 1 250,00',
    'as_of_label' => 'Sākuma atlikums uz datumu',
    'as_of_help' => 'Datums, uz kuru iepriekš minētais skaitlis ir patiess.',

    'divergence' => 'Šis skaitlis vairāk nekā par 500 € atšķiras no atlikuma, ko Beatrax aprēķina no jūsu importētajiem darījumiem. Vai tiešām turpināt?',
    'use_beatrax' => 'Izmantot Beatrax skaitli',
    'use_mine' => 'Izmantot manu skaitli',

    'save' => 'Saglabāt sākuma atlikumu',
    'remove' => 'Noņemt sākuma atlikumu',
    'saved' => 'Saglabāts.',
    'removed' => 'Noņemts.',

    'toast' => [
        'updated' => 'Sākuma atlikums atjaunināts.',
        'removed' => 'Sākuma atlikums noņemts.',
    ],

    'errors' => [
        'invalid_number' => 'Sākuma atlikumam jābūt derīgam skaitlim.',
        'date_required' => 'Izvēlieties datumu, uz kuru šis sākuma atlikums attiecas.',
        'date_invalid' => 'Sākuma atlikuma datumam jābūt derīgam ISO datumam (YYYY-MM-DD).',
        'date_future' => 'Sākuma atlikuma datums nevar būt nākotnē.',
    ],
];
