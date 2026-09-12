<?php

declare(strict_types=1);

return [
    'conflict' => [

        'field' => [
            'amount_minor' => 'Betrag',
            'currency' => 'Währung',
            'description' => 'Beschreibung',
            'counterparty_name' => 'Händlername',
            'default' => 'Wert',
        ],
        'heading_cleaner' => 'Ein E-Mail-Beleg hat beim Feld :field einen klareren Wert',
        'heading_different' => 'Ein E-Mail-Beleg hat beim Feld :field einen anderen Wert',
        'title' => 'Beleg und Kontoauszug stimmen nicht überein.',
        'body' => ':heading („:receipt“) als der Kontoauszug („:statement“). Soll Beatrax bei künftigen Konflikten Belege bevorzugen?',
        'use_receipt' => 'Beleg verwenden',
        'keep_statement' => 'Kontoauszug behalten',
        'heading_restated' => 'Ein späterer Kontoauszug hat beim Feld :field einen anderen Wert',
        'restated_title' => 'Die Bank hat diesen Umsatz korrigiert.',
        'restated_body' => ':heading („:incoming“) als die bereits gespeicherte Zeile („:stored“). Soll Beatrax bei künftigen Abweichungen den neuen Wert bevorzugen?',
        'use_restated' => 'Neuen Wert verwenden',
        'keep_stored' => 'Gespeicherten Wert behalten',
    ],
];
