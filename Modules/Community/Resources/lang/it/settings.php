<?php

declare(strict_types=1);

return [
    'about_body' => "Un file YAML incluso che associa i codici criptici degli estratti conto a nomi di esercenti leggibili. Attivandola, Beatrax può leggere la lista durante l'importazione; inviare una proposta apre GitHub nel tuo browser.",

    'mappings' => ':count corrispondenza|:count corrispondenze',
    'contributors' => ':count collaboratore|:count collaboratori',

    'use_shared_list' => [
        'title' => 'Usa la lista condivisa degli esercenti',
        'help' => 'Consenti a Beatrax di leggere la lista inclusa per completare i nomi leggibili degli esercenti che non hai rinominato tu.',
    ],

    'offer_to_contribute' => [
        'title' => 'Proponi di contribuire',
        'help' => 'Mostra il pulsante «Aiuta gli altri a identificarlo» nella riga di smistamento, così puoi inviare una proposta alla lista condivisa con un clic.',
    ],

    'update_on_updates' => [
        'title' => "Aggiorna la lista condivisa agli aggiornamenti dell'app",
        'help' => 'Aggiorna la lista inclusa ogni volta che Beatrax si aggiorna.',
        'help_phone' => 'Aggiorna la lista inclusa ogni volta che viene installata una nuova versione di Beatrax dall\'App Store o da Google Play.',
        'note' => "Si attiva con un futuro aggiornamento dell'app — vedi Impostazioni → Informazioni per la versione attuale.",
    ],
];
