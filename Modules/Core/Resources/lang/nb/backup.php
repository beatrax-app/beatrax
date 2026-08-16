<?php

declare(strict_types=1);

return [
    'download' => [
        'unavailable' => 'Krypterte sikkerhetskopier er tilgjengelige i skrivebordsversjonen (SQLite). På en serverdatabase bruker du databasens egne verktøy for sikkerhetskopiering.',
        'intro' => 'Last ned en kopi av hele databasen din kryptert med en passordfrase — trygg å oppbevare på en ekstern disk eller i skylagring, fordi den ikke kan leses uten passordfrasen (kvantesikker XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Passordfrase',
        'confirm_passphrase' => 'Bekreft passordfrasen',
        'keep_safe' => 'Oppbevar passordfrasen trygt — uten den kan ikke sikkerhetskopien gjenopprettes.',
        'submit' => 'Last ned kryptert sikkerhetskopi',
        'preparing' => 'Forbereder…',
    ],

    'restore' => [
        'heading' => 'Gjenopprett fra en sikkerhetskopi',

        'intro_html' => 'Erstatt den nåværende databasen din med en kryptert sikkerhetskopi. Filen dekrypteres og kontrolleres før noe endres, og et øyeblikksbilde av dagens data lagres først — men dette <strong class="text-slate-700 dark:text-slate-200">overskriver alt</strong>, så det er sperret.',
        'restored' => 'Gjenopprettet. Last appen på nytt for å se de gjenopprettede dataene dine.',
        'snapshot_saved_prefix' => 'Et øyeblikksbilde av de tidligere dataene dine ble lagret i',
        'file_label' => 'Kryptert sikkerhetskopi (.enc)',
        'uploading' => 'Laster opp…',
        'passphrase' => 'Passordfrase',
        'confirm_prefix' => 'Skriv',
        'confirm_suffix' => 'for å bekrefte',
        'submit' => 'Gjenopprett (overskriver nåværende data)',
        'restoring' => 'Gjenoppretter…',
    ],

    'errors' => [
        'passphrase_min' => 'Bruk en passordfrase på minst :min tegn.',
        'passphrase_mismatch' => 'De to passordfrasene er ikke like.',
        'download_sqlite_only' => 'Kryptert nedlasting er bare tilgjengelig i SQLite-versjonen.',
        'create_failed' => 'Kunne ikke opprette sikkerhetskopien: :message',
        'confirm_phrase' => 'Skriv :phrase for å bekrefte — dette erstatter de nåværende dataene dine.',
        'choose_file' => 'Velg en kryptert sikkerhetskopi (.enc) som skal gjenopprettes.',
        'enter_passphrase' => 'Skriv inn passordfrasen sikkerhetskopien ble kryptert med.',
        'unreadable' => 'Den opplastede filen kunne ikke leses. Prøv igjen.',
    ],
];
