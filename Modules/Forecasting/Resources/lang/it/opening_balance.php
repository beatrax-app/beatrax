<?php

declare(strict_types=1);

return [
    'help_paypal' => 'Le esportazioni di PayPal non contengono righe di saldo, quindi imposta questo valore a mano.',
    'help_asn' => 'Ancorato automaticamente al tuo ultimo estratto conto. Sovrascrivi solo se sai che il saldo reale è diverso.',
    'help_default' => 'Sovrascrivi solo se sai che il saldo reale attuale è diverso da quello calcolato da Beatrax.',

    'legend' => 'Saldo iniziale della previsione per :name',
    'opening_label' => 'Saldo iniziale',
    'opening_placeholder' => 'es. 1.250,00',
    'as_of_label' => 'Saldo iniziale alla data',
    'as_of_help' => 'La data a cui si riferisce la cifra qui sopra.',

    'divergence' => 'Questo si discosta di oltre :threshold dal saldo che Beatrax calcola dalle transazioni importate. Sei sicuro?',
    'use_beatrax' => 'Usa il numero di Beatrax',
    'use_mine' => 'Usa il mio numero',

    'save' => 'Salva il saldo iniziale',
    'remove' => 'Rimuovi il saldo iniziale',
    'saved' => 'Salvato.',
    'removed' => 'Rimosso.',

    'toast' => [
        'updated' => 'Saldo iniziale aggiornato.',
        'removed' => 'Saldo iniziale rimosso.',
    ],

    'errors' => [
        'invalid_number' => 'Il saldo iniziale deve essere un numero valido.',
        'date_required' => 'Scegli la data a cui si applica questo saldo iniziale.',
        'date_invalid' => 'La data del saldo iniziale deve essere una data ISO valida (YYYY-MM-DD).',
        'date_future' => 'La data del saldo iniziale non può essere nel futuro.',
    ],
];
