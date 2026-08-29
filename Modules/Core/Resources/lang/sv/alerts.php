<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Systemvarningar',

    'actions' => [
        'install_next_launch' => 'Installera vid nästa start',
        'install_next_launch_aria' => 'Installera vid nästa start — markerar systemvarning #:id som åtgärdad',
        'skip_version' => 'Hoppa över den här versionen',
        'release_notes' => 'Versionsinformation →',
        'update_now' => 'Uppdatera nu',
        'update_now_aria' => 'Uppdatera nu — markerar systemvarning #:id som åtgärdad',
        'remind_later' => 'Påminn mig senare',
        'mark_resolved' => 'Markera som åtgärdad',
        'mark_resolved_aria' => 'Markera som åtgärdad — systemvarning #:id',
    ],

    'messages' => [
        'update_available' => 'Uppdatering tillgänglig — Beatrax :version är klar. Den installeras vid nästa start.',
        'update_stale' => 'Du kör version :current — version :latest har funnits i 30 dagar. Uppdatera nu.',
        'update_critical' => 'Kritisk uppdatering tillgänglig — version :version åtgärdar :summary. Installera så snart som möjligt.',
        'backup_corrupt_with_path' => 'Säkerhetskopian som skrevs :timestamp klarade inte integritetskontrollen. Granska :path. Åtgärda detta innan du förlitar dig på säkerhetskopior.',
        'backup_corrupt_no_path' => 'Säkerhetskopieringen som försöktes :timestamp avbröts innan någon fil skapades — källdatabasen klarade inte integritetskontrollen. Åtgärda detta innan du förlitar dig på säkerhetskopior.',

        'backup_overdue' => 'Den senaste verifierade säkerhetskopian är :hoursh gammal. Kör <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> eller vänta på den schemalagda körningen kl. 03:00.',
        'wal_mode_missing' => 'SQLite är inte i WAL-läge (för närvarande :mode). Samtidiga skrivningar kan fastna. Kör <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> för vägledning.',
        'synchronous_misconfigured' => 'SQLites synchronous-nivå är :level (förväntat NORMAL/1). Hållbarheten kan skilja sig från konfigurationen. Kör <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> för vägledning.',
        'oauth_scrub_set_failed' => 'Maskeringen av OAuth-hemligheter är ur funktion. Loggar och granskningsutdrag kan innehålla omaskerade token fram till nästa lyckade inläsning.',
        'oauth_reauth_required' => 'OAuth-hemligheter har flyttats till lagring per användare. Auktorisera Gmail och Microsoft på nytt för att återuppta e-postskanningen. Den gamla hemlighetsfilen bytte namn till :file för återställning.',
        'oauth_reconsent' => 'Återanslut din :provider',
        'auth_recovery_code_consumed' => 'Återställningskod använd av :username.',
        'auth_recovery_code_failed' => 'Misslyckat försök med återställningskod för :username.',
        'auth_lock_hard_cap_reached' => 'Utloggad efter för många misslyckade PIN-försök.',
        'open_banking_reconsent' => 'Återanslut din bank',
        'auth_lock_corrupted_key' => 'Din PIN-kod kan inte öppna applåset på den här enheten: den sparade nyckeln går inte att läsa. Logga in med ditt kontolösenord för att ange en ny PIN-kod.',
        'sync_gdk_rewrap_failed' => 'Ompaketeringen av GDK-nyckelringen misslyckades efter att applåsets lösenfras ändrats — krypterade data kan vara oåterkalleliga tills nyckelringen paketerats om.',
        'worker_crashed' => 'Beatrax bakgrundsbearbetning stoppades oväntat. Importer och e-postskanningar är pausade. Öppna appen igen för att starta om den.',
        'auth_lock_key_material_stranded' => 'Kryptering i vila är aktiv för det här kontot, men ingen applås-inpackning håller längre datanyckeln, så varje krypterad anteckning, beskrivning och motpartsuppgift läses som tom. Parkoppling med en enhet som fortfarande har nyckeln är enda vägen tillbaka.',
        'auth_lock_recovery_wrap_stale' => 'Kontolösenordet ändrades utan att applåsets återställningsinpackning packades om, så det lösenordet öppnar inte längre applåset. PIN-koden gör det fortfarande. Länka kontolösenordet på nytt i applåsinställningarna medan PIN-koden fortfarande är känd — annars lämnar en glömd PIN-kod ingenting efter sig.',
        'reconnect_link' => 'Återanslut →',
    ],
];
