<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Systemadvarsler',

    'actions' => [
        'install_next_launch' => 'Installér ved næste opstart',
        'install_next_launch_aria' => 'Installér ved næste opstart — markerer systemadvarsel #:id som løst',
        'skip_version' => 'Spring denne version over',
        'release_notes' => 'Udgivelsesnoter →',
        'update_now' => 'Opdatér nu',
        'update_now_aria' => 'Opdatér nu — markerer systemadvarsel #:id som løst',
        'remind_later' => 'Påmind mig senere',
        'mark_resolved' => 'Markér som løst',
        'mark_resolved_aria' => 'Markér som løst — systemadvarsel #:id',
    ],

    'messages' => [
        'update_available' => 'Opdatering tilgængelig — Beatrax :version er klar. Den installeres ved næste opstart.',
        'update_stale' => 'Du kører version :current — version :latest har været tilgængelig i 30 dage. Opdatér nu.',
        'update_critical' => 'Kritisk opdatering tilgængelig — version :version retter :summary. Installér hurtigst muligt.',
        'backup_corrupt_with_path' => 'Sikkerhedskopien, der blev skrevet :timestamp, bestod ikke integritetstjekket. Undersøg :path. Løs det, før du stoler på sikkerhedskopier.',
        'backup_corrupt_no_path' => 'Sikkerhedskopien, der blev forsøgt :timestamp, blev afbrudt, før der blev oprettet en fil — kildedatabasen bestod ikke integritetstjekket. Løs det, før du stoler på sikkerhedskopier.',

        'backup_overdue' => 'Den seneste verificerede sikkerhedskopi er :hoursh gammel. Kør <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code>, eller vent på den planlagte kørsel kl. 03:00.',
        'wal_mode_missing' => 'SQLite kører ikke i WAL-tilstand (aktuelt :mode). Samtidige skrivninger kan gå i stå. Kør <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> for vejledning.',
        'synchronous_misconfigured' => 'SQLites synchronous-niveau er :level (forventet NORMAL/1). Holdbarheden kan afvige fra konfigurationen. Kør <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> for vejledning.',
        'oauth_scrub_set_failed' => 'Maskering af OAuth-hemmeligheder er ude af drift. Logfiler og revisionsuddrag kan indeholde umaskerede tokens indtil næste vellykkede indlæsning.',
        'oauth_reauth_required' => 'OAuth-hemmeligheder er flyttet til lagring pr. bruger. Godkend Gmail og Microsoft igen for at genoptage e-mailscanning. Den gamle hemmelighedsfil blev omdøbt til :file med henblik på tilbagerulning.',
        'oauth_reconsent' => 'Tilslut din :provider igen',
        'auth_recovery_code_consumed' => 'Gendannelseskode brugt af :username.',
        'auth_recovery_code_failed' => 'Mislykket forsøg med gendannelseskode for :username.',
        'auth_lock_hard_cap_reached' => 'Logget ud efter for mange mislykkede PIN-forsøg.',
        'open_banking_reconsent' => 'Tilslut din bank igen',
        'auth_lock_corrupted_key' => 'Din PIN kan ikke åbne applåsen på denne enhed: den gemte nøgle kan ikke læses. Log ind med din kontoadgangskode for at angive en ny PIN.',
        'sync_gdk_rewrap_failed' => 'Ompakning af GDK-nøgleringen efter en ændring af applåsens adgangssætning mislykkedes — krypterede data kan være uoprettelige, indtil nøgleringen er pakket om.',
        'worker_crashed' => 'Beatrax’ baggrundsbehandling stoppede uventet. Import og e-mailscanning er sat på pause. Åbn appen igen for at genstarte den.',
        'auth_lock_key_material_stranded' => 'Kryptering i hvile er aktiv for denne konto, men ingen applås-indpakning holder længere datanøglen, så hver krypteret note, beskrivelse og modpartsoplysning læses som tom. Parring med en enhed, der stadig har nøglen, er den eneste vej tilbage.',
        'auth_lock_recovery_wrap_stale' => 'Kontoadgangskoden blev ændret, uden at applåsens gendannelsesindpakning blev pakket om, så den adgangskode åbner ikke længere applåsen. Det gør PIN-koden stadig. Sammenkæd kontoadgangskoden igen i applåsindstillingerne, mens PIN-koden stadig kendes — ellers efterlader en glemt PIN intet.',
        'reconnect_link' => 'Tilslut igen →',
    ],
];
