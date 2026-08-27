<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Sistēmas brīdinājumi',

    'actions' => [
        'install_next_launch' => 'Instalēt nākamajā palaišanas reizē',
        'install_next_launch_aria' => 'Instalēt nākamajā palaišanas reizē — atzīmē sistēmas brīdinājumu #:id kā atrisinātu',
        'skip_version' => 'Izlaist šo versiju',
        'release_notes' => 'Laidiena piezīmes →',
        'update_now' => 'Atjaunināt tagad',
        'update_now_aria' => 'Atjaunināt tagad — atzīmē sistēmas brīdinājumu #:id kā atrisinātu',
        'remind_later' => 'Atgādināt vēlāk',
        'mark_resolved' => 'Atzīmēt kā atrisinātu',
        'mark_resolved_aria' => 'Atzīmēt kā atrisinātu — sistēmas brīdinājums #:id',
    ],

    'messages' => [
        'update_available' => 'Pieejams atjauninājums — Beatrax :version ir gatavs. Tas tiks instalēts nākamajā palaišanas reizē.',
        'update_stale' => 'Jūs izmantojat versiju :current — versija :latest ir pieejama jau 30 dienas. Atjauniniet tagad.',
        'update_critical' => 'Pieejams kritisks atjauninājums — versija :version novērš :summary. Instalējiet to pēc iespējas ātrāk.',
        'backup_corrupt_with_path' => 'Dublējums, kas izveidots :timestamp, neizturēja integritātes pārbaudi. Pārbaudiet :path. Atrisiniet to, pirms paļaujaties uz dublējumiem.',
        'backup_corrupt_no_path' => 'Dublējums, kas tika sākts :timestamp, tika pārtraukts, pirms izveidojās kāds fails — avota datubāze neizturēja integritātes pārbaudi. Atrisiniet to, pirms paļaujaties uz dublējumiem.',

        'backup_overdue' => 'Jaunākais pārbaudītais dublējums ir :hoursh vecs. Palaidiet <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> vai gaidiet plānoto izpildi plkst. 03:00.',
        'wal_mode_missing' => 'SQLite nedarbojas WAL režīmā (pašlaik :mode). Vienlaicīgi ieraksti var apstāties. Norādēm palaidiet <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>.',
        'synchronous_misconfigured' => 'SQLite synchronous līmenis ir :level (gaidīts NORMAL/1). Datu noturība var atšķirties no konfigurācijā norādītās. Norādēm palaidiet <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>.',
        'oauth_scrub_set_failed' => 'OAuth noslēpumu maskēšana nedarbojas. Žurnālos un audita izrakstos līdz nākamajai veiksmīgajai ielādei var būt nemaskētas pilnvaras.',
        'oauth_reauth_required' => 'OAuth noslēpumi ir pārvietoti uz katra lietotāja krātuvi. Atkārtoti autorizējiet Gmail un Microsoft, lai atsāktu e-pasta skenēšanu. Vecais noslēpumu fails atgriešanai tika pārdēvēts par :file.',
        'oauth_reconsent' => 'Atkārtoti savienojiet savu :provider',
        'reconnect_link' => 'Pievienot atkārtoti →',
    ],
];
