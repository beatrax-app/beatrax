<?php

declare(strict_types=1);

return [
    'help_paypal' => 'PayPal-exports bevatten geen saldoregels, dus stel dit handmatig in.',
    'help_asn' => 'Automatisch verankerd vanuit je laatste afschrift. Overschrijf dit alleen als je weet dat het actuele saldo afwijkt.',
    'help_default' => 'Overschrijf dit alleen als je weet dat het huidige actuele saldo afwijkt van wat Beatrax berekent.',

    'legend' => 'Beginsaldo prognose voor :name',
    'opening_label' => 'Beginsaldo',
    'opening_placeholder' => 'bijv. 1.250,00',
    'as_of_label' => 'Beginsaldo per',
    'as_of_help' => 'De datum waarop het bedrag hierboven geldt.',

    'divergence' => 'Dit wijkt meer dan € 500 af van het saldo dat Beatrax berekent op basis van je geïmporteerde transacties. Weet je het zeker?',
    'use_beatrax' => 'Gebruik het getal van Beatrax',
    'use_mine' => 'Gebruik mijn getal',

    'save' => 'Beginsaldo opslaan',
    'saved' => 'Opgeslagen.',

    'toast' => [
        'updated' => 'Beginsaldo bijgewerkt.',
    ],

    'errors' => [
        'invalid_number' => 'Beginsaldo moet een geldig getal zijn.',
        'date_required' => 'Kies de datum waarop dit beginsaldo van toepassing is.',
        'date_invalid' => 'De beginsaldodatum moet een geldige ISO-datum zijn (JJJJ-MM-DD).',
        'date_future' => 'De beginsaldodatum mag niet in de toekomst liggen.',
    ],
];
