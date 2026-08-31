<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Den här appen kan inte lämna över en fil till din enhet, så den krypterade säkerhetskopian skapas i skrivbordsappen i stället. Para den här enheten för att hålla dem synkade.',
        'unavailable' => 'Krypterade säkerhetskopior är tillgängliga i skrivbordsversionen (SQLite). På en serverdatabas använder du databasens egna verktyg för säkerhetskopiering.',
        'intro' => 'Ladda ner en kopia av hela din databas krypterad med en lösenfras — trygg att förvara på en extern disk eller i molnlagring, eftersom den är oläsbar utan lösenfrasen (kvantsäker XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Lösenfras',
        'confirm_passphrase' => 'Bekräfta lösenfrasen',
        'keep_safe' => 'Förvara lösenfrasen säkert — utan den går säkerhetskopian inte att återskapa.',
        'submit' => 'Ladda ner krypterad säkerhetskopia',
        'preparing' => 'Förbereder…',
    ],

    'restore' => [
        'heading' => 'Återställ från en säkerhetskopia',

        'intro_html' => 'Ersätt din nuvarande databas med en krypterad säkerhetskopia. Filen dekrypteras och kontrolleras innan något ändras, och en ögonblicksbild av dina nuvarande data sparas först — men detta <strong class="text-slate-700 dark:text-slate-200">skriver över allt</strong>, så det är spärrat. Du loggas ut, eftersom din inloggning också ligger i databasen.',
        'restored' => 'Din säkerhetskopia har återställts. Logga in med det användarnamn och lösenord som gällde när den skapades.',
        'snapshot_saved_prefix' => 'En ögonblicksbild av dina tidigare data sparades i',
        'file_label' => 'Krypterad säkerhetskopia (.enc)',
        'uploading' => 'Laddar upp…',
        'passphrase' => 'Lösenfras',
        'confirm_prefix' => 'Skriv',
        'confirm_suffix' => 'för att bekräfta',
        'submit' => 'Återställ (skriver över nuvarande data)',
        'restoring' => 'Återställer…',
    ],

    'errors' => [
        'passphrase_min' => 'Använd en lösenfras med minst :min tecken.|Använd en lösenfras med minst :min tecken.',
        'passphrase_mismatch' => 'De två lösenfraserna stämmer inte överens.',
        'download_sqlite_only' => 'Krypterad nedladdning är bara tillgänglig i SQLite-versionen.',
        'create_failed' => 'Kunde inte skapa säkerhetskopian: :message',
        'confirm_phrase' => 'Skriv :phrase för att bekräfta — detta ersätter dina nuvarande data.',
        'choose_file' => 'Välj en krypterad säkerhetskopia (.enc) att återställa.',
        'upload_failed' => 'Filen laddades inte upp färdigt. Den kan vara för stor för den här enheten — återställning i skrivbordsappen tar emot en större säkerhetskopia.',
        'enter_passphrase' => 'Ange lösenfrasen som säkerhetskopian krypterades med.',
        'unreadable' => 'Den uppladdade filen kunde inte läsas. Försök igen.',
        'restore_wrong_passphrase' => 'Den lösenfrasen öppnade inte den här säkerhetskopian, och inget har ändrats. Skriv den igen och försök på nytt. Om den säkert är rätt har filen ändrats sedan den skapades — återställ då från en annan kopia.',
        'restore_not_a_backup' => 'Den här filen är ingen krypterad Beatrax-säkerhetskopia, så det finns inget att återställa och inget har ändrats. Välj .enc-filen som appen skrev när du gjorde säkerhetskopian.',
        'restore_contents_unreadable' => 'Säkerhetskopian öppnades, men databasen i den är skadad, så den återställdes inte och inget har ändrats. Återställ från en tidigare säkerhetskopia.',
        'restore_could_not_read' => 'Säkerhetskopian gick inte att läsa, så återställningen kördes inte och inget har ändrats. Kontrollera att enheten har ledigt utrymme och försök igen.',
        'restore_not_supported' => 'Återställning fungerar i den version som håller sina data i en enda fil, vilket den här inte är, så inget har ändrats. Använd databasens egna återställningsverktyg för en serverdatabas.',
        'restore_failed' => 'Återställningen kördes inte, och inget har ändrats. Försök igen — om det fortsätter att misslyckas noterar appens logg vad som stoppade den.',
    ],
];
