<?php

declare(strict_types=1);

return [
    'download' => [
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

        'intro_html' => 'Ersätt din nuvarande databas med en krypterad säkerhetskopia. Filen dekrypteras och kontrolleras innan något ändras, och en ögonblicksbild av dina nuvarande data sparas först — men detta <strong class="text-slate-700 dark:text-slate-200">skriver över allt</strong>, så det är spärrat.',
        'restored' => 'Återställt. Ladda om appen för att se dina återställda data.',
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
        'enter_passphrase' => 'Ange lösenfrasen som säkerhetskopian krypterades med.',
        'unreadable' => 'Den uppladdade filen kunde inte läsas. Försök igen.',
    ],
];
