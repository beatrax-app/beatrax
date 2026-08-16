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
        'reconnect_link' => 'Tilslut igen →',
    ],
];
