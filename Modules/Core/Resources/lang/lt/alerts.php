<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Sistemos įspėjimai',

    'actions' => [
        'install_next_launch' => 'Įdiegti kitą kartą paleidus',
        'install_next_launch_aria' => 'Įdiegti kitą kartą paleidus — sistemos įspėjimas #:id pažymimas kaip išspręstas',
        'skip_version' => 'Praleisti šią versiją',
        'release_notes' => 'Versijos aprašas →',
        'update_now' => 'Atnaujinti dabar',
        'update_now_aria' => 'Atnaujinti dabar — sistemos įspėjimas #:id pažymimas kaip išspręstas',
        'remind_later' => 'Priminti vėliau',
        'mark_resolved' => 'Žymėti kaip išspręstą',
        'mark_resolved_aria' => 'Žymėti kaip išspręstą — sistemos įspėjimas #:id',
    ],

    'messages' => [
        'update_available' => 'Yra atnaujinimas — Beatrax :version paruošta. Ji bus įdiegta kitą kartą paleidus.',
        'update_stale' => 'Naudoji :current versiją — :latest versija prieinama jau 30 dienų. Atnaujink dabar.',
        'update_critical' => 'Yra svarbus atnaujinimas — :version versija ištaiso: :summary. Įdiek kuo greičiau.',
        'backup_corrupt_with_path' => 'Atsarginė kopija, sukurta :timestamp, neišlaikė vientisumo patikros. Patikrink :path. Išspręsk tai, kol nepradėjai pasikliauti atsarginėmis kopijomis.',
        'backup_corrupt_no_path' => 'Atsarginė kopija, bandyta sukurti :timestamp, nutrūko dar nesukūrus jokio failo — šaltinio duomenų bazė neišlaikė vientisumo patikros. Išspręsk tai, kol nepradėjai pasikliauti atsarginėmis kopijomis.',

        'backup_overdue' => 'Naujausiai patikrintai atsarginei kopijai jau :hoursh. Paleisk <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> arba palauk suplanuoto paleidimo 03:00.',
        'wal_mode_missing' => 'SQLite veikia ne WAL režimu (dabar :mode). Lygiagretus rašymas gali stringti. Paleisk <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>, kad gautum nurodymų.',
        'synchronous_misconfigured' => 'SQLite synchronous lygis yra :level (tikėtasi NORMAL/1). Patvarumo elgsena gali skirtis nuo konfigūracijos. Paleisk <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>, kad gautum nurodymų.',
        'reconnect_link' => 'Prijungti iš naujo →',
    ],
];
