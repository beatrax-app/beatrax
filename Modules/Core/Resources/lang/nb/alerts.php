<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Systemvarsler',

    'actions' => [
        'install_next_launch' => 'Installer ved neste oppstart',
        'install_next_launch_aria' => 'Installer ved neste oppstart — merker systemvarsel #:id som løst',
        'skip_version' => 'Hopp over denne versjonen',
        'release_notes' => 'Versjonsnotater →',
        'update_now' => 'Oppdater nå',
        'update_now_aria' => 'Oppdater nå — merker systemvarsel #:id som løst',
        'remind_later' => 'Minn meg på det senere',
        'mark_resolved' => 'Merk som løst',
        'mark_resolved_aria' => 'Merk som løst — systemvarsel #:id',
    ],

    'messages' => [
        'update_available' => 'Oppdatering tilgjengelig — Beatrax :version er klar. Den installeres ved neste oppstart.',
        'update_stale' => 'Du bruker versjon :current — versjon :latest har vært tilgjengelig i 30 dager. Oppdater nå.',
        'update_critical' => 'Kritisk oppdatering tilgjengelig — versjon :version retter :summary. Installer så snart som mulig.',
        'backup_corrupt_with_path' => 'Sikkerhetskopien som ble skrevet :timestamp, besto ikke integritetssjekken. Undersøk :path. Løs dette før du stoler på sikkerhetskopier.',
        'backup_corrupt_no_path' => 'Sikkerhetskopieringen som ble forsøkt :timestamp, ble avbrutt før noen fil ble laget — kildedatabasen besto ikke integritetssjekken. Løs dette før du stoler på sikkerhetskopier.',

        'backup_overdue' => 'Den nyeste verifiserte sikkerhetskopien er :hoursh gammel. Kjør <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code>, eller vent på den planlagte kjøringen kl. 03:00.',
        'wal_mode_missing' => 'SQLite er ikke i WAL-modus (nå :mode). Samtidige skrivinger kan stoppe opp. Kjør <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> for veiledning.',
        'synchronous_misconfigured' => 'SQLite-nivået for synchronous er :level (forventet NORMAL/1). Holdbarheten kan avvike fra konfigurasjonen. Kjør <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> for veiledning.',
        'oauth_scrub_set_failed' => 'Sladding av OAuth-hemmeligheter er ute av drift. Logger og revisjonsutdrag kan inneholde usladdede tokener fram til neste vellykkede innlasting.',
        'oauth_reauth_required' => 'OAuth-hemmeligheter er flyttet til lagring per bruker. Godkjenn Gmail og Microsoft på nytt for å gjenoppta e-postskanning. Den gamle hemmelighetsfilen ble omdøpt til :file for tilbakerulling.',
        'oauth_reconsent' => 'Koble til :provider på nytt',
        'reconnect_link' => 'Koble til på nytt →',
    ],
];
