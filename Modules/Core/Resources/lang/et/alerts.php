<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Süsteemi hoiatused',

    'actions' => [
        'install_next_launch' => 'Paigalda järgmisel käivitamisel',
        'install_next_launch_aria' => 'Paigalda järgmisel käivitamisel — märgib süsteemi hoiatuse #:id lahendatuks',
        'skip_version' => 'Jäta see versioon vahele',
        'release_notes' => 'Väljalaske märkmed →',
        'update_now' => 'Uuenda kohe',
        'update_now_aria' => 'Uuenda kohe — märgib süsteemi hoiatuse #:id lahendatuks',
        'remind_later' => 'Tuleta hiljem meelde',
        'mark_resolved' => 'Märgi lahendatuks',
        'mark_resolved_aria' => 'Märgi lahendatuks — süsteemi hoiatus #:id',
    ],

    'messages' => [
        'update_available' => 'Uuendus on saadaval — Beatrax :version on valmis. See paigaldatakse järgmisel käivitamisel.',
        'update_stale' => 'Kasutad versiooni :current — versioon :latest on olnud saadaval 30 päeva. Uuenda kohe.',
        'update_critical' => 'Saadaval on kriitiline uuendus — versioon :version parandab: :summary. Paigalda esimesel võimalusel.',
        'backup_corrupt_with_path' => 'Varukoopia, mis kirjutati :timestamp, ei läbinud terviklikkuse kontrolli. Kontrolli asukohta :path. Lahenda see enne, kui varukoopiatele toetud.',
        'backup_corrupt_no_path' => 'Varukoopia, mida üritati teha :timestamp, katkes enne ühegi faili loomist — lähteandmebaas ei läbinud terviklikkuse kontrolli. Lahenda see enne, kui varukoopiatele toetud.',

        'backup_overdue' => 'Viimane kontrollitud varukoopia on :hoursh vana. Käivita <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> või oota kell 03.00 toimuvat ajastatud käivitust.',
        'wal_mode_missing' => 'SQLite ei ole WAL-režiimis (praegu :mode). Samaaegsed kirjutamised võivad takerduda. Juhiste saamiseks käivita <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>.',
        'synchronous_misconfigured' => 'SQLite synchronous-tase on :level (oodatud NORMAL/1). Andmete püsivus võib konfiguratsioonist erineda. Juhiste saamiseks käivita <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>.',
        'oauth_scrub_set_failed' => 'OAuth-saladuste varjamine ei tööta. Logid ja auditi väljavõtted võivad kuni järgmise õnnestunud laadimiseni sisaldada varjamata lubasid.',
        'oauth_reauth_required' => 'OAuth-saladused viidi kasutajapõhisesse hoidlasse. Autoriseeri Gmail ja Microsoft uuesti, et e-kirjade skannimine jätkuks. Vana saladuste fail nimetati tagasipööramiseks ümber failiks :file.',
        'oauth_reconsent' => 'Ühenda oma :provider uuesti',
        'reconnect_link' => 'Ühenda uuesti →',
    ],
];
