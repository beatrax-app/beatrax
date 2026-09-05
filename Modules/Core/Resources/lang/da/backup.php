<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Denne app kan ikke aflevere en fil til din enhed, så den krypterede sikkerhedskopi laves i computerappen i stedet. Par denne enhed for at holde de to synkroniseret.',
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
        'restored' => 'Din sikkerhedskopi er gendannet. Log ind med det brugernavn og den adgangskode, der var i brug, da den blev lavet.',
        'snapshot_saved_prefix' => 'Et øjebliksbillede af dine tidligere data blev gemt i',
        'file_label' => 'Sikkerhedskopi (.enc) eller eksportarkiv (.zip)',
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
        'choose_file' => 'Vælg, hvad der skal gendannes fra: .enc-filen med sikkerhedskopien eller .zip-filen, som eksporten med ét klik skrev.',
        'upload_failed' => 'Filen blev ikke uploadet færdig. Den er måske for stor til denne enhed — gendannelse i skrivebordsappen accepterer en større sikkerhedskopi.',
        'enter_passphrase' => 'Indtast den adgangssætning, sikkerhedskopien blev krypteret med.',
        'unreadable' => 'Den uploadede fil kunne ikke læses. Prøv igen.',
        'restore_wrong_passphrase' => 'Den adgangssætning åbnede ikke denne sikkerhedskopi, og intet er ændret. Skriv den igen, og prøv på ny. Er den helt sikkert rigtig, er filen ændret siden den blev lavet — gendan så fra en anden kopi.',
        'restore_not_a_backup' => 'Denne fil indeholder ingen Beatrax-sikkerhedskopi, så der er intet at gendanne, og intet er ændret. Vælg den .enc-fil, appen skrev, da du lavede sikkerhedskopien, eller den .zip, som eksporten med ét klik skrev.',
        'restore_contents_unreadable' => 'Sikkerhedskopien blev åbnet, men databasen i den er beskadiget, så den blev ikke gendannet, og intet er ændret. Gendan fra en tidligere sikkerhedskopi.',
        'restore_could_not_read' => 'Sikkerhedskopifilen kunne ikke læses, så gendannelsen blev ikke kørt, og intet er ændret. Tjek at enheden har ledig plads, og prøv igen.',
        'restore_not_supported' => 'Gendannelse virker i den udgave, der holder sine data i én fil, og det er denne ikke, så intet er ændret. Brug databasens egne gendannelsesværktøjer ved en serverdatabase.',
        'restore_failed' => 'Gendannelsen blev ikke kørt, og intet er ændret. Prøv igen — bliver den ved med at fejle, noterer appens log, hvad der stoppede den.',
    ],
];
