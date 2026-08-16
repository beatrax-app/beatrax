<?php

declare(strict_types=1);

return [
    'wizard' => [
        'intro' => 'Lege eine E-Mail-Nachricht (.eml) oder ein Postfach-Archiv (.mbox) ab. Der Abgleich erkennt PayPal-Belege und zeigt sie als kanonische Transaktionen an; nicht zugeordnete Absender bleiben zur Triage im Prüfprotokoll.',
    ],

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
    ],
];
