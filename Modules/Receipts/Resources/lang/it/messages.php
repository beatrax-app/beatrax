<?php

declare(strict_types=1);

return [
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
        'heading_restated' => 'Un estratto conto successivo registra un valore diverso nel campo :field',
        'restated_title' => 'La banca ha rettificato questo movimento.',
        'restated_body' => ':heading («:incoming») rispetto alla riga già salvata («:stored»). Vuoi che Beatrax dia la preferenza al nuovo valore nelle prossime divergenze?',
        'use_restated' => 'Usa il nuovo valore',
        'keep_stored' => 'Mantieni il valore salvato',
    ],
];
