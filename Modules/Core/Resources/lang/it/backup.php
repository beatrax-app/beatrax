<?php

declare(strict_types=1);

return [
    'download' => [
        'unavailable' => 'I backup crittografati sono disponibili nella build desktop (SQLite). Su un database server, usa gli strumenti di backup del database stesso.',
        'intro' => 'Scarica una copia crittografata con passphrase di tutto il tuo database — puoi conservarla senza rischi su un disco esterno o nel cloud, perché è illeggibile senza la passphrase (XChaCha20-Poly1305 + Argon2id, sicuri contro il calcolo quantistico).',
        'passphrase' => 'Passphrase',
        'confirm_passphrase' => 'Conferma passphrase',
        'keep_safe' => 'Conserva la passphrase in un posto sicuro — senza di essa non è possibile recuperare il backup.',
        'submit' => 'Scarica il backup crittografato',
        'preparing' => 'Preparazione…',
    ],

    'restore' => [
        'heading' => 'Ripristina da un backup',

        'intro_html' => 'Sostituisci il database attuale con un backup crittografato. Il file viene decrittato e verificato prima che qualcosa cambi, e prima del ripristino viene salvato uno snapshot dei dati attuali — ma questa operazione <strong class="text-slate-700 dark:text-slate-200">sovrascrive tutto</strong>, quindi è protetta.',
        'restored' => "Ripristino completato. Ricarica l'app per vedere i dati ripristinati.",
        'snapshot_saved_prefix' => 'Uno snapshot dei tuoi dati precedenti è stato salvato in',
        'file_label' => 'Backup crittografato (.enc)',
        'uploading' => 'Caricamento…',
        'passphrase' => 'Passphrase',
        'confirm_prefix' => 'Digita',
        'confirm_suffix' => 'per confermare',
        'submit' => 'Ripristina (sovrascrive i dati attuali)',
        'restoring' => 'Ripristino…',
    ],

    'errors' => [
        'passphrase_min' => 'Usa una passphrase di almeno :min caratteri.',
        'passphrase_mismatch' => 'Le due passphrase non coincidono.',
        'download_sqlite_only' => 'Il download crittografato è disponibile solo nella build SQLite.',
        'create_failed' => 'Impossibile creare il backup: :message',
        'confirm_phrase' => 'Digita :phrase per confermare — questa operazione sostituisce i tuoi dati attuali.',
        'choose_file' => 'Scegli un file di backup crittografato (.enc) da ripristinare.',
        'enter_passphrase' => 'Inserisci la passphrase con cui è stato crittografato il backup.',
        'unreadable' => 'Non è stato possibile leggere il file caricato. Riprova.',
    ],
];
