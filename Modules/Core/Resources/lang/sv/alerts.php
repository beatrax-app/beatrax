<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Systemvarningar',

    'actions' => [
        'download_and_install' => 'Ladda ner och installera',
        'download_and_install_aria' => 'Ladda ner och installera — markerar systemvarning #:id som åtgärdad',
        'skip_version' => 'Hoppa över den här versionen',
        'release_notes' => 'Versionsinformation →',
        'update_now' => 'Uppdatera nu',
        'update_now_aria' => 'Uppdatera nu — markerar systemvarning #:id som åtgärdad',
        'remind_later' => 'Påminn mig senare',
        'mark_resolved' => 'Markera som åtgärdad',
        'mark_resolved_aria' => 'Markera som åtgärdad — systemvarning #:id',
        'assign_in_budgets' => 'Fördela i Budgetar',
        'dismiss' => 'Stäng',
        'dismiss_aria' => 'Stäng — systemvarning #:id',
    ],

    'deferred_pass' => [
        'budget-nudges' => 'budgetvarningarna',
        'daily-triggers' => 'de dagliga påminnelserna och sammanfattningen',
    ],

    'messages' => [
        'update_available' => 'Uppdatering tillgänglig — Beatrax :version. Ingenting laddas ner förrän du själv väljer att installera; Beatrax stängs sedan och öppnas igen på den nya versionen.',
        'update_refused' => 'Beatrax laddade ner version :version och vägrade installera den — filen stämde inte med utgivarens signatur, så inget på den här enheten ändrades. En skadad nedladdning kan orsaka det. Händer det igen, installera inte Beatrax från den källan.',
        'update_stale' => 'Du kör version :current — version :latest har funnits i 30 dagar. Uppdatera nu.',
        'update_critical' => 'Kritisk uppdatering tillgänglig — version :version åtgärdar :summary. Installera så snart som möjligt.',
        'backup_corrupt_with_path' => 'Säkerhetskopian som skrevs :timestamp klarade inte integritetskontrollen. Granska :path. Åtgärda detta innan du förlitar dig på säkerhetskopior.',
        'backup_corrupt_no_path' => 'Säkerhetskopieringen som försöktes :timestamp avbröts innan någon fil skapades — källdatabasen klarade inte integritetskontrollen. Åtgärda detta innan du förlitar dig på säkerhetskopior.',
        'backup_write_failed' => 'Säkerhetskopian som påbörjades :timestamp slutfördes inte — databasen klarade sina kontroller, men filerna kunde inte skrivas. Kontrollera ledigt utrymme och behörigheter på säkerhetskopiemappen.',
        'backup_restore_failed' => 'Återställningen som påbörjades :timestamp slutfördes inte. Dina tidigare data sparades först i :snapshot.',

        'backup_overdue' => 'Den senaste verifierade säkerhetskopian är :hoursh gammal. Beatrax gör den här säkerhetskopian själv, en gång om dagen, medan appen är öppen — det finns inget att köra för hand. Om den fortsätter vara så gammal har appen inte varit öppen när den dagliga körningen kom.',
        'backup_none_found' => 'Ingen verifierad säkerhetskopia hittades i säkerhetskopiemappen. Beatrax gör den här säkerhetskopian själv, en gång om dagen, medan appen är öppen — det finns inget att köra för hand.',
        'wal_mode_missing' => 'Databasen är inte i WAL-läge (just nu :mode), så sparandet kan pausa medan en bakgrundsuppgift körs. Beatrax sätter WAL vid varje start, så en omstart brukar lösa det.',
        'synchronous_misconfigured' => 'Databasens hållbarhetsnivå är :level i stället för förväntad NORMAL. Beatrax sätter den vid varje start, så en omstart brukar lösa det.',
        'oauth_scrub_set_failed' => 'Maskeringen av OAuth-hemligheter är ur funktion. Loggar och granskningsutdrag kan innehålla omaskerade token fram till nästa lyckade inläsning.',
        'oauth_reauth_required' => 'OAuth-hemligheter har flyttats till lagring per användare. Auktorisera Gmail och Microsoft på nytt för att återuppta e-postskanningen. Den gamla hemlighetsfilen bytte namn till :file för återställning.',
        'oauth_reconsent' => 'Återanslut din :provider',
        'auth_recovery_code_consumed' => 'Återställningskod använd av :username.',
        'auth_recovery_code_failed' => 'Misslyckat försök med återställningskod för :username.',
        'auth_lock_hard_cap_reached' => 'Utloggad efter för många misslyckade PIN-försök.',
        'open_banking_reconsent' => 'Återanslut din bank',
        'open_banking_nothing_imported' => 'Din bank skickade transaktioner, men Beatrax kunde inte registrera någon av dem, så ingenting nådde din bokföring. Öppna inställningarna under Open banking för att se varför.',
        'auth_lock_corrupted_key' => 'Din PIN-kod kan inte öppna applåset på den här enheten: den sparade nyckeln går inte att läsa. Logga in med ditt kontolösenord för att ange en ny PIN-kod.',
        'sync_gdk_rewrap_failed' => 'Ompaketeringen av GDK-nyckelringen misslyckades efter att applåsets lösenfras ändrats — krypterade data kan vara oåterkalleliga tills nyckelringen paketerats om.',
        'worker_crashed' => 'Beatrax bakgrundsbearbetning stoppades oväntat. Importer och e-postskanningar är pausade. Öppna appen igen för att starta om den.',
        'auth_lock_key_material_stranded' => 'Kryptering i vila är aktiv för det här kontot, men ingen applås-inpackning håller längre datanyckeln, så varje krypterad anteckning, beskrivning och motpartsuppgift läses som tom. Återställ en krypterad säkerhetskopia som gjordes medan nyckeln fortfarande fungerade, eller sätt upp det här kontot på nytt på en enhet som fortfarande har den.',
        'auth_lock_recovery_wrap_stale' => 'Kontolösenordet ändrades utan att applåsets återställningsinpackning packades om, så det lösenordet öppnar inte längre applåset. PIN-koden gör det fortfarande. Länka kontolösenordet på nytt i applåsinställningarna medan PIN-koden fortfarande är känd — annars lämnar en glömd PIN-kod ingenting efter sig.',
        'reconnect_link' => 'Återanslut →',
        'pots_category_link_retired' => 'Kuvertbudgetering har ersatt sparpotter som var kopplade till en kategori. :amount från :count arkiverad sparpott är ofördelat igen och väntar på att du fördelar beloppet.|Kuvertbudgetering har ersatt sparpotter som var kopplade till en kategori. :amount från :count arkiverade sparpotter är ofördelat igen och väntar på att du fördelar beloppet.',
        'notifications_deferred_pass_failed' => 'Beatrax kunde inte räkna ut :pass på den här enheten, så några kan saknas. Den försöker igen varje gång du öppnar appen.',
    ],
];
