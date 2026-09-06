<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Systemvarsler',

    'actions' => [
        'download_and_install' => 'Last ned og installer',
        'download_and_install_aria' => 'Last ned og installer — merker systemvarsel #:id som løst',
        'skip_version' => 'Hopp over denne versjonen',
        'release_notes' => 'Versjonsnotater →',
        'update_now' => 'Oppdater nå',
        'update_now_aria' => 'Oppdater nå — merker systemvarsel #:id som løst',
        'remind_later' => 'Minn meg på det senere',
        'mark_resolved' => 'Merk som løst',
        'mark_resolved_aria' => 'Merk som løst — systemvarsel #:id',
        'assign_in_budgets' => 'Fordel i Budsjetter',
        'dismiss' => 'Lukk',
        'dismiss_aria' => 'Lukk — systemvarsel #:id',
    ],

    'deferred_pass' => [
        'budget-nudges' => 'budsjettvarslene',
        'daily-triggers' => 'de daglige påminnelsene og sammendraget',
    ],

    'messages' => [
        'update_available' => 'Oppdatering tilgjengelig — Beatrax :version. Ingenting lastes ned før du velger å installere; Beatrax lukkes så og åpnes igjen på den nye versjonen.',
        'update_refused' => 'Beatrax lastet ned versjon :version og nektet å installere den — filen stemte ikke med utgiverens signatur, så ingenting på denne enheten ble endret. En skadet nedlasting kan utløse det. Skjer det igjen, ikke installer Beatrax fra den kilden.',
        'update_stale' => 'Du bruker versjon :current — versjon :latest har vært tilgjengelig i 30 dager. Oppdater nå.',
        'update_critical' => 'Kritisk oppdatering tilgjengelig — versjon :version retter :summary. Installer så snart som mulig.',
        'backup_corrupt_with_path' => 'Sikkerhetskopien som ble skrevet :timestamp, besto ikke integritetssjekken. Undersøk :path. Løs dette før du stoler på sikkerhetskopier.',
        'backup_corrupt_no_path' => 'Sikkerhetskopieringen som ble forsøkt :timestamp, ble avbrutt før noen fil ble laget — kildedatabasen besto ikke integritetssjekken. Løs dette før du stoler på sikkerhetskopier.',
        'backup_write_failed' => 'Sikkerhetskopien som ble forsøkt :timestamp, ble ikke fullført — databasen besto kontrollene sine, men filene kunne ikke skrives. Sjekk ledig plass og rettigheter på sikkerhetskopimappen.',
        'backup_restore_failed' => 'Gjenopprettingen som ble forsøkt :timestamp, ble ikke fullført. De tidligere dataene dine ble lagret først, i :snapshot.',

        'backup_overdue' => 'Den nyeste verifiserte sikkerhetskopien er :hoursh gammel. Beatrax lager denne sikkerhetskopien selv, én gang om dagen, mens appen er åpen — det er ingenting å kjøre for hånd. Blir den værende så gammel, har ikke appen vært åpen da den daglige kjøringen kom.',
        'backup_none_found' => 'Det ble ikke funnet noen verifisert sikkerhetskopi i sikkerhetskopimappen. Beatrax lager denne sikkerhetskopien selv, én gang om dagen, mens appen er åpen — det er ingenting å kjøre for hånd.',
        'wal_mode_missing' => 'Databasen er ikke i WAL-modus (nå :mode), så lagring kan stoppe opp mens en bakgrunnsoppgave kjører. Beatrax setter WAL ved hver oppstart, så en omstart løser det som regel.',
        'synchronous_misconfigured' => 'Databasens holdbarhetsnivå er :level i stedet for forventet NORMAL. Beatrax setter det ved hver oppstart, så en omstart løser det som regel.',
        'oauth_scrub_set_failed' => 'Sladding av OAuth-hemmeligheter er ute av drift. Logger og revisjonsutdrag kan inneholde usladdede tokener fram til neste vellykkede innlasting.',
        'oauth_reauth_required' => 'OAuth-hemmeligheter er flyttet til lagring per bruker. Godkjenn Gmail og Microsoft på nytt for å gjenoppta e-postskanning. Den gamle hemmelighetsfilen ble omdøpt til :file for tilbakerulling.',
        'oauth_reconsent' => 'Koble til :provider på nytt',
        'auth_recovery_code_consumed' => 'Gjenopprettingskode brukt av :username.',
        'auth_recovery_code_failed' => 'Mislykket forsøk med gjenopprettingskode for :username.',
        'auth_lock_hard_cap_reached' => 'Logget ut etter for mange mislykkede PIN-forsøk.',
        'open_banking_reconsent' => 'Koble til banken din på nytt',
        'open_banking_nothing_imported' => 'Banken din sendte transaksjoner, men Beatrax kunne ikke registrere noen av dem, så ingenting nådde regnskapet ditt. Åpne innstillingene under Open banking for å se hvorfor.',
        'auth_lock_corrupted_key' => 'PIN-koden din kan ikke åpne applåsen på denne enheten: den lagrede nøkkelen kan ikke leses. Logg inn med kontopassordet ditt for å angi en ny PIN-kode.',
        'sync_gdk_rewrap_failed' => 'Ompakking av GDK-nøkkelringen mislyktes etter en endring av applåsens passfrase — krypterte data kan være uopprettelige til nøkkelringen er pakket om.',
        'worker_crashed' => 'Bakgrunnsbehandlingen i Beatrax stoppet uventet. Import og e-postskanning er satt på pause. Åpne appen på nytt for å starte den igjen.',
        'auth_lock_key_material_stranded' => 'Kryptering i hvile er aktiv for denne kontoen, men ingen applås-innpakning holder lenger datanøkkelen, så hvert kryptert notat, beskrivelse og motpartsdetalj leses som tomt. Gjenopprett en kryptert sikkerhetskopi som ble laget mens nøkkelen fortsatt virket, eller sett opp denne kontoen på nytt på en enhet som fortsatt har den.',
        'auth_lock_recovery_wrap_stale' => 'Kontopassordet ble endret uten at applåsens gjenopprettingsinnpakning ble pakket om, så det passordet åpner ikke lenger applåsen. PIN-koden gjør det fortsatt. Koble kontopassordet på nytt fra applåsinnstillingene mens PIN-koden fortsatt er kjent — ellers etterlater en glemt PIN-kode ingenting.',
        'reconnect_link' => 'Koble til på nytt →',
        'pots_category_link_retired' => 'Konvoluttbudsjettering har erstattet sparepotter som var knyttet til en kategori. :amount fra :count arkivert sparepott er ufordelt igjen og venter på at du fordeler beløpet.|Konvoluttbudsjettering har erstattet sparepotter som var knyttet til en kategori. :amount fra :count arkiverte sparepotter er ufordelt igjen og venter på at du fordeler beløpet.',
        'notifications_deferred_pass_failed' => 'Beatrax klarte ikke å regne ut :pass på denne enheten, så noen kan mangle. Den prøver igjen hver gang du åpner appen.',
    ],
];
