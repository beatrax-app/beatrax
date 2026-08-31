<?php

declare(strict_types=1);

return [
    'page_title' => 'Kasboek',
    'heading' => 'Kasboek',
    'intro' => 'Leg contante en andere uitgaven buiten de bank met de hand vast. Handmatige invoer komt in hetzelfde grootboek terecht als je imports — ze worden gecategoriseerd, aan een tegenpartij gekoppeld, herkend als terugkerend en meegeteld voor je maand.',

    'direction' => 'Richting',
    'expense' => 'Uitgave',
    'income' => 'Inkomsten',

    'amount' => 'Bedrag (:symbol)',
    'date' => 'Datum',
    'counterparty' => 'Tegenpartij',
    'counterparty_placeholder' => 'bijv. Bakkerij',
    'category' => 'Categorie',
    'optional' => '(optioneel)',
    'uncategorized' => 'Zonder categorie',
    'note' => 'Notitie',

    'add_entry' => 'Invoer toevoegen',
    'manual_entries' => 'Handmatige invoer',
    'no_entries' => 'Nog geen handmatige invoer.',
    'delete_entry' => 'Invoer verwijderen',
    'delete_entry_caption' => 'Verwijderen',
    'delete' => 'Verwijderen',
    'delete_confirm' => 'Deze boeking verwijderen?',
    'delete_keep' => 'Behouden',

    'errors' => [
        'amount_positive' => 'Voer een bedrag groter dan nul in.',
        'amount_too_large' => 'Dit bedrag is te groot. Controleer de cijfers.',
        'amount_unreadable' => 'Het bedrag kon niet worden gelezen. Voer het in met maximaal :decimals decimaal, bijvoorbeeld :example.|Het bedrag kon niet worden gelezen. Voer het in met maximaal :decimals decimalen, bijvoorbeeld :example.',
        'amount_unreadable_whole' => 'Het bedrag kon niet worden gelezen. Deze valuta heeft geen decimalen, voer dus een heel getal in, bijvoorbeeld :example.',
        'invalid_date' => 'Voer een geldige datum in.',
        'not_recorded' => 'De boeking is niet vastgelegd. Probeer hem opnieuw toe te voegen.',
    ],

    'toast' => [
        'added' => 'Contante invoer toegevoegd.',
        'removed' => 'Contante invoer verwijderd.',
        'reconciled_locked' => 'Deze transactie is afgestemd. Hef de afstemming op om wijzigingen te maken.',
    ],
];
