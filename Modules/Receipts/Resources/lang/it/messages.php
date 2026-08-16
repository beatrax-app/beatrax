<?php

declare(strict_types=1);

return [
    'wizard' => [
        'intro' => 'Trascina qui un messaggio email (.eml) o un archivio di casella (.mbox). Il matcher riconosce le ricevute PayPal e le presenta come transazioni canoniche; i mittenti non riconosciuti restano nel log di audit per lo smistamento.',
    ],

    'conflict' => [

        'field' => [
            'amount_minor' => 'importo',
            'currency' => 'valuta',
            'description' => 'descrizione',
            'counterparty_name' => 'nome esercente',
            'default' => 'valore',
        ],
        'heading_cleaner' => 'Una ricevuta email ha un valore più chiaro nel campo :field',
        'heading_different' => 'Una ricevuta email registra un valore diverso nel campo :field',
        'title' => 'Ricevuta ed estratto conto non coincidono.',
        'body' => ":heading («:receipt») rispetto all'estratto conto («:statement»). Vuoi che Beatrax dia la preferenza alle ricevute nei prossimi conflitti?",
        'use_receipt' => 'Usa la ricevuta',
        'keep_statement' => "Mantieni l'estratto conto",
    ],
];
