<?php

declare(strict_types=1);

return [
    'help_paypal' => 'PayPal-exporter innehåller inga saldorader, så ange det här manuellt.',
    'help_default' => 'Skriv bara över värdet om du vet att det aktuella saldot skiljer sig från det Beatrax räknar fram.',

    'legend' => 'Ingående saldo för prognosen för :name',
    'opening_label' => 'Ingående saldo',
    'opening_placeholder' => 't.ex. :amount',
    'as_of_label' => 'Ingående saldo per',
    'as_of_help' => 'Det datum som siffran ovan gäller för.',

    'divergence' => 'Det här skiljer sig mer än :threshold från det saldo Beatrax räknar fram utifrån dina importerade transaktioner. Är du säker?',
    'computed_is' => 'Beatrax beräknar :amount.',
    'use_beatrax' => 'Använd siffran från Beatrax',
    'use_mine' => 'Använd min siffra',

    'save' => 'Spara ingående saldo',
    'remove' => 'Ta bort ingående saldo',
    'saved' => 'Sparat.',
    'removed' => 'Borttaget.',

    'toast' => [
        'updated' => 'Ingående saldo har uppdaterats.',
        'removed' => 'Ingående saldo har tagits bort.',
    ],

    'errors' => [
        'invalid_number' => 'Ingående saldo måste vara ett giltigt tal.',
        'date_required' => 'Välj det datum som det här ingående saldot gäller för.',
        'date_invalid' => 'Datumet för ingående saldo måste vara ett giltigt ISO-datum (YYYY-MM-DD).',
        'date_future' => 'Datumet för ingående saldo kan inte ligga i framtiden.',
    ],
];
