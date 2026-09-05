<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Denne appen kan ikke levere en fil til enheten din, så den krypterte sikkerhetskopien lages i skrivebordsappen i stedet. Par denne enheten for å holde dem synkronisert.',
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

        'intro_html' => 'Erstatt den nåværende databasen din med en kryptert sikkerhetskopi. Filen dekrypteres og kontrolleres før noe endres, og et øyeblikksbilde av dagens data lagres først — men dette <strong class="text-slate-700 dark:text-slate-200">overskriver alt</strong>, så det er sperret. Du blir logget ut, for innloggingen din ligger også i databasen.',
        'restored' => 'Sikkerhetskopien er gjenopprettet. Logg inn med brukernavnet og passordet som gjaldt da den ble laget.',
        'snapshot_saved_prefix' => 'Et øyeblikksbilde av de tidligere dataene dine ble lagret i',
        'file_label' => 'Sikkerhetskopi (.enc) eller eksportarkiv (.zip)',
        'uploading' => 'Laster opp…',
        'passphrase' => 'Passordfrase',
        'confirm_prefix' => 'Skriv',
        'confirm_suffix' => 'for å bekrefte',
        'submit' => 'Gjenopprett (overskriver nåværende data)',
        'restoring' => 'Gjenoppretter…',
    ],

    'errors' => [
        'passphrase_min' => 'Bruk en passordfrase på minst :min tegn.|Bruk en passordfrase på minst :min tegn.',
        'passphrase_mismatch' => 'De to passordfrasene er ikke like.',
        'download_sqlite_only' => 'Kryptert nedlasting er bare tilgjengelig i SQLite-versjonen.',
        'create_failed' => 'Kunne ikke opprette sikkerhetskopien: :message',
        'confirm_phrase' => 'Skriv :phrase for å bekrefte — dette erstatter de nåværende dataene dine.',
        'choose_file' => 'Velg hva du vil gjenopprette fra: .enc-filen med sikkerhetskopien, eller .zip-filen eksporten med ett klikk skrev.',
        'upload_failed' => 'Filen ble ikke lastet opp ferdig. Den er kanskje for stor for denne enheten — gjenoppretting i skrivebordsappen godtar en større sikkerhetskopi.',
        'enter_passphrase' => 'Skriv inn passordfrasen sikkerhetskopien ble kryptert med.',
        'unreadable' => 'Den opplastede filen kunne ikke leses. Prøv igjen.',
        'restore_wrong_passphrase' => 'Den passordfrasen åpnet ikke denne sikkerhetskopien, og ingenting er endret. Skriv den inn på nytt og prøv igjen. Er den helt sikkert riktig, er filen endret etter at den ble laget — gjenopprett da fra en annen kopi.',
        'restore_not_a_backup' => 'Denne filen inneholder ingen Beatrax-sikkerhetskopi, så det er ingenting å gjenopprette, og ingenting er endret. Velg .enc-filen appen skrev da du laget sikkerhetskopien, eller .zip-filen eksporten med ett klikk skrev.',
        'restore_contents_unreadable' => 'Sikkerhetskopien ble åpnet, men databasen i den er skadet, så den ble ikke gjenopprettet, og ingenting er endret. Gjenopprett fra en eldre sikkerhetskopi.',
        'restore_could_not_read' => 'Sikkerhetskopifilen kunne ikke leses, så gjenopprettingen ble ikke kjørt, og ingenting er endret. Sjekk at enheten har ledig plass, og prøv igjen.',
        'restore_not_supported' => 'Gjenoppretting virker i utgaven som holder dataene sine i én fil, og det er ikke denne, så ingenting er endret. Bruk databasens egne gjenopprettingsverktøy ved en serverdatabase.',
        'restore_failed' => 'Gjenopprettingen ble ikke kjørt, og ingenting er endret. Prøv igjen — fortsetter den å feile, noterer apploggen hva som stoppet den.',
    ],
];
