<?php

declare(strict_types=1);

return [
    'help_paypal' => 'PayPal-exports bevatten geen saldoregels, dus stel dit handmatig in.',
    'help_default' => 'Overschrijf dit alleen als je weet dat het huidige actuele saldo afwijkt van wat Beatrax berekent.',

    'legend' => 'Beginsaldo prognose voor :name',
    'opening_label' => 'Beginsaldo',
    'opening_placeholder' => 'bijv. :amount',
    'as_of_label' => 'Beginsaldo per',
    'as_of_help' => 'De datum waarop het bedrag hierboven geldt.',

    'divergence' => 'Dit wijkt meer dan :threshold af van het saldo dat Beatrax berekent op basis van je geïmporteerde transacties. Weet je het zeker?',
    'computed_is' => 'Beatrax berekent :amount.',
    'use_beatrax' => 'Gebruik het getal van Beatrax',
    'use_mine' => 'Gebruik mijn getal',

    'save' => 'Beginsaldo opslaan',
    'remove' => 'Beginsaldo verwijderen',
    'saved' => 'Opgeslagen.',
    'removed' => 'Verwijderd.',

    'toast' => [
        'updated' => 'Beginsaldo bijgewerkt.',
        'removed' => 'Beginsaldo verwijderd.',
    ],

    'errors' => [
        'invalid_number' => 'Beginsaldo moet een geldig getal zijn.',
        'date_required' => 'Kies de datum waarop dit beginsaldo van toepassing is.',
        'date_invalid' => 'De beginsaldodatum moet een geldige ISO-datum zijn (JJJJ-MM-DD).',
        'date_future' => 'De beginsaldodatum mag niet in de toekomst liggen.',
    ],
];
