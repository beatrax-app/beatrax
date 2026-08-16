<?php

declare(strict_types=1);

return [
    'page_title' => 'Importa da YNAB / Actual',

    'eyebrow' => 'Migrazioni',
    'heading' => 'Importa da YNAB / Actual',
    'intro' => 'Porta qui il tuo albero delle categorie, lo storico dei budget e le transazioni da YNAB4, dal nuovo YNAB o da Actual Budget. Nulla viene scritto nel tuo registro finché non rivedi e confermi.',
    'reconcile_context' => 'Controllo degli aggiornamenti rispetto alla tua ultima importazione da :product.',

    'source_label' => 'Origine',
    'file_label' => 'File',
    'parse_button' => "Analizza l'export",

    'hints' => [
        'ynab4' => 'Esporta il budget completo come file ZIP dal menu File → Export di YNAB4.',
        'nynab' => 'Esporta il budget da nYNAB tramite File → Export Budget, poi comprimi in ZIP i file CSV esportati.',
        'actual' => 'Esporta il budget come file ZIP da Settings → Export data di Actual Budget.',
    ],

    'errors' => [
        'unrecognised' => 'Questo non sembra un export di YNAB4, nYNAB o Actual che possiamo leggere. Controlla il file e riprova.',
        'file_too_large' => 'Quel file è troppo grande per un export di migrazione.',
    ],
];
