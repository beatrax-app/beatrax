<?php

declare(strict_types=1);

return [
    'page_title' => 'Gestisci :name · Beatrax',
    'heading' => 'Gestisci :name',
    'subtitle' => 'Visualizza, reimposta o rigenera i codici di questo utente.',

    'set_password' => [
        'heading' => 'Imposta una nuova password per questo utente',
        'description' => 'Al prossimo accesso dovrà scegliere una password.',
        'open' => 'Imposta una nuova password per questo utente',
        'body' => 'Imposta una nuova password per :name. Al prossimo accesso dovrà scegliere una password.',
        'label' => 'Nuova password',
        'submit' => 'Imposta la password',
        'cancel' => 'Annulla',
    ],

    'regenerate' => [
        'heading' => 'Rigenera i codici di recupero di questo utente',
        'description' => 'I vecchi codici verranno invalidati.',
        'open' => 'Rigenera i codici di recupero di questo utente',
        'body' => 'I codici non ancora usati smetteranno di funzionare. Vedrai i 10 nuovi codici una volta sola e potrai consegnarli.',
        'confirm_label' => 'Digita il nome utente per continuare',
        'submit' => 'Rigenera i codici',
        'keep' => 'Mantieni i codici attuali',
        'download' => 'Scarica come .txt',
    ],

    'error_min_length' => 'Usa almeno 12 caratteri.',
    'password_set' => 'Password impostata per :name. Al prossimo accesso dovrà scegliere una password.',
    'codes_regenerated' => 'Dieci nuovi codici di recupero generati per :name.',
];
