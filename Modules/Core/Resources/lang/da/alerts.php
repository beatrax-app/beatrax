<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Systemadvarsler',

    'actions' => [
        'download_and_install' => 'Hent og installér',
        'download_and_install_aria' => 'Hent og installér — markerer systemadvarsel #:id som løst',
        'skip_version' => 'Spring denne version over',
        'release_notes' => 'Udgivelsesnoter →',
        'update_now' => 'Opdatér nu',
        'update_now_aria' => 'Opdatér nu — markerer systemadvarsel #:id som løst',
        'remind_later' => 'Påmind mig senere',
        'mark_resolved' => 'Markér som løst',
        'mark_resolved_aria' => 'Markér som løst — systemadvarsel #:id',
        'assign_in_budgets' => 'Fordel i Budgetter',
        'dismiss' => 'Luk',
        'dismiss_aria' => 'Luk — systemadvarsel #:id',
    ],

    'deferred_pass' => [
        'budget-nudges' => 'budgetadvarslerne',
        'daily-triggers' => 'de daglige påmindelser og oversigten',
    ],

    'messages' => [
        'update_available' => 'Opdatering tilgængelig — Beatrax :version. Der hentes intet, før du vælger at installere; derefter lukker Beatrax og åbner igen i den nye version.',
        'update_refused' => 'Beatrax hentede version :version og nægtede at installere den — filen matchede ikke udgiverens signatur, så intet på denne enhed blev ændret. En beskadiget download kan udløse det. Sker det igen, så installer ikke Beatrax fra den kilde.',
        'update_stale' => 'Du kører version :current — version :latest har været tilgængelig i 30 dage. Opdatér nu.',
        'update_critical' => 'Kritisk opdatering tilgængelig — version :version retter :summary. Installér hurtigst muligt.',
        'backup_corrupt_with_path' => 'Sikkerhedskopien, der blev skrevet :timestamp, bestod ikke integritetstjekket. Undersøg :path. Løs det, før du stoler på sikkerhedskopier.',
        'backup_corrupt_no_path' => 'Sikkerhedskopien, der blev forsøgt :timestamp, blev afbrudt, før der blev oprettet en fil — kildedatabasen bestod ikke integritetstjekket. Løs det, før du stoler på sikkerhedskopier.',
        'backup_write_failed' => 'Sikkerhedskopien, der blev forsøgt :timestamp, blev ikke gennemført — databasen bestod sine kontroller, men filerne kunne ikke skrives. Tjek ledig plads og rettigheder på sikkerhedskopimappen.',
        'backup_restore_failed' => 'Gendannelsen, der blev forsøgt :timestamp, blev ikke gennemført. Dine tidligere data blev gemt først, i :snapshot.',

        'backup_overdue' => 'Den seneste verificerede sikkerhedskopi er :hoursh gammel. Beatrax laver denne sikkerhedskopi selv, én gang om dagen, mens appen er åben — der er ikke noget at køre manuelt. Bliver den ved med at være så gammel, har appen ikke været åben, da den daglige kørsel kom forbi.',
        'backup_none_found' => 'Der blev ikke fundet nogen verificeret sikkerhedskopi i sikkerhedskopimappen. Beatrax laver denne sikkerhedskopi selv, én gang om dagen, mens appen er åben — der er ikke noget at køre manuelt.',
        'wal_mode_missing' => 'Databasen er ikke i WAL-tilstand (aktuelt :mode), så det kan gå i stå at gemme, mens en baggrundsopgave kører. Beatrax sætter WAL ved hver start, så en genstart løser det som regel.',
        'synchronous_misconfigured' => 'Databasens holdbarhedsniveau er :level i stedet for det forventede NORMAL. Beatrax sætter det ved hver start, så en genstart løser det som regel.',
        'oauth_scrub_set_failed' => 'Maskering af OAuth-hemmeligheder er ude af drift. Logfiler og revisionsuddrag kan indeholde umaskerede tokens indtil næste vellykkede indlæsning.',
        'oauth_reauth_required' => 'OAuth-hemmeligheder er flyttet til lagring pr. bruger. Godkend Gmail og Microsoft igen for at genoptage e-mailscanning. Den gamle hemmelighedsfil blev omdøbt til :file med henblik på tilbagerulning.',
        'oauth_reconsent' => 'Tilslut din :provider igen',
        'auth_recovery_code_consumed' => 'Gendannelseskode brugt af :username.',
        'auth_recovery_code_failed' => 'Mislykket forsøg med gendannelseskode for :username.',
        'auth_lock_hard_cap_reached' => 'Logget ud efter for mange mislykkede PIN-forsøg.',
        'open_banking_reconsent' => 'Tilslut din bank igen',
        'open_banking_nothing_imported' => 'Din bank sendte transaktioner, men Beatrax kunne ikke registrere nogen af dem, så intet nåede frem til din bogføring. Åbn indstillingerne under Open banking for at se hvorfor.',
        'auth_lock_corrupted_key' => 'Din PIN kan ikke åbne applåsen på denne enhed: den gemte nøgle kan ikke læses. Log ind med din kontoadgangskode for at angive en ny PIN.',
        'sync_gdk_rewrap_failed' => 'Ompakning af GDK-nøgleringen efter en ændring af applåsens adgangssætning mislykkedes — krypterede data kan være uoprettelige, indtil nøgleringen er pakket om.',
        'worker_crashed' => 'Beatrax’ baggrundsbehandling stoppede uventet. Import og e-mailscanning er sat på pause. Åbn appen igen for at genstarte den.',
        'auth_lock_key_material_stranded' => 'Kryptering i hvile er aktiv for denne konto, men ingen applås-indpakning holder længere datanøglen, så hver krypteret note, beskrivelse og modpartsoplysning læses som tom. Gendan en krypteret sikkerhedskopi, der blev lavet, mens nøglen stadig virkede, eller sæt denne konto op igen på en enhed, der stadig har den.',
        'auth_lock_recovery_wrap_stale' => 'Kontoadgangskoden blev ændret, uden at applåsens gendannelsesindpakning blev pakket om, så den adgangskode åbner ikke længere applåsen. Det gør PIN-koden stadig. Sammenkæd kontoadgangskoden igen i applåsindstillingerne, mens PIN-koden stadig kendes — ellers efterlader en glemt PIN intet.',
        'reconnect_link' => 'Tilslut igen →',
        'pots_category_link_retired' => 'Kuvertbudgettering har afløst puljer, der var knyttet til en kategori. :amount fra :count arkiveret pulje er ufordelt igen og venter på, at du fordeler beløbet.|Kuvertbudgettering har afløst puljer, der var knyttet til en kategori. :amount fra :count arkiverede puljer er ufordelt igen og venter på, at du fordeler beløbet.',
        'notifications_deferred_pass_failed' => 'Beatrax kunne ikke beregne :pass på denne enhed, så nogle mangler måske. Den prøver igen, hver gang du åbner appen.',
    ],
];
