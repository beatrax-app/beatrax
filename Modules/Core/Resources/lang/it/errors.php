<?php

declare(strict_types=1);

return [
    'back' => 'Torna a Beatrax',

    '404' => [
        'title' => 'Questa pagina non esiste',
        'body' => 'Il link potrebbe essere vecchio, o la pagina è stata rinominata. Ai tuoi dati non è successo nulla.',
    ],
    '4xx' => [
        'title' => 'Questa richiesta non può essere gestita',
        'body' => 'La pagina è stata aperta in un modo che non prevede. I tuoi dati non sono cambiati.',
    ],

    '419' => [
        'title' => 'La tua sessione è scaduta',
        'body' => 'Sei stato via abbastanza a lungo da far scadere la pagina. Riapri Beatrax e continua.',
    ],

    '500' => [
        'title' => 'Qualcosa è andato storto',
        'body' => 'Il problema è stato annotato nel registro di questo dispositivo. I tuoi dati non sono stati modificati.',
    ],

    '503' => [
        'title' => 'Beatrax non è disponibile per un momento',
        'body' => 'Un aggiornamento o una manutenzione sta finendo. Riprova tra poco.',
    ],
];
