<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Denne telefon kan ikke gemme en fil, appen rækker den, så den krypterede sikkerhedskopi laves i skrivebordsappen. Par denne enhed for at holde de to synkroniseret.',
        'unavailable' => 'Krypterede sikkerhedskopier er tilgængelige i skrivebordsversionen (SQLite). På en serverdatabase bruger du databasens egne værktøjer til sikkerhedskopiering.',
        'intro' => 'Hent en kopi af hele din database krypteret med en adgangssætning — sikker at opbevare på et eksternt drev eller i skylageret, fordi den ikke kan læses uden adgangssætningen (kvantesikker XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Adgangssætning',
        'confirm_passphrase' => 'Bekræft adgangssætning',
        'keep_safe' => 'Opbevar adgangssætningen sikkert — uden den kan sikkerhedskopien ikke gendannes.',
        'submit' => 'Hent krypteret sikkerhedskopi',
        'preparing' => 'Forbereder…',
    ],

    'restore' => [
        'heading' => 'Gendan fra en sikkerhedskopi',

        'intro_html' => 'Erstat din nuværende database med en krypteret sikkerhedskopi. Filen dekrypteres og kontrolleres, før noget ændres, og et øjebliksbillede af dine nuværende data gemmes først — men det <strong class="text-slate-700 dark:text-slate-200">overskriver alt</strong>, så det er spærret. Du bliver logget ud, for din indlogning ligger også i databasen.',
        'restored' => 'Gendannet. Genindlæs appen for at se dine gendannede data.',
        'snapshot_saved_prefix' => 'Et øjebliksbillede af dine tidligere data blev gemt i',
        'file_label' => 'Krypteret sikkerhedskopi (.enc)',
        'uploading' => 'Uploader…',
        'passphrase' => 'Adgangssætning',
        'confirm_prefix' => 'Skriv',
        'confirm_suffix' => 'for at bekræfte',
        'submit' => 'Gendan (overskriver nuværende data)',
        'restoring' => 'Gendanner…',
    ],

    'errors' => [
        'passphrase_min' => 'Brug en adgangssætning på mindst :min tegn.|Brug en adgangssætning på mindst :min tegn.',
        'passphrase_mismatch' => 'De to adgangssætninger er ikke ens.',
        'download_sqlite_only' => 'Krypteret download er kun tilgængelig i SQLite-versionen.',
        'create_failed' => 'Sikkerhedskopien kunne ikke oprettes: :message',
        'confirm_phrase' => 'Skriv :phrase for at bekræfte — det erstatter dine nuværende data.',
        'choose_file' => 'Vælg en krypteret sikkerhedskopi (.enc), der skal gendannes.',
        'enter_passphrase' => 'Indtast den adgangssætning, sikkerhedskopien blev krypteret med.',
        'unreadable' => 'Den uploadede fil kunne ikke læses. Prøv igen.',
    ],
];
