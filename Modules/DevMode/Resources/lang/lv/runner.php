<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan izpildītājs',
    'subtitle' => 'Izpildiet DROŠĀS komandas ar vienu klikšķi; DESTRUKTĪVĀS komandas ir aiz trīskāršās aizsardzības.',
    'run_a_command' => 'Izpildīt komandu',
    'filter_aria' => 'Izpilžu filtrs',
    'filter' => [
        'all' => 'Visas',
        'running' => 'Izpildās',
        'failed' => 'Neizdevās',
        'destructive' => 'Destruktīvās',
    ],
    'worker_running' => 'Rindas darbinieks: DARBOJAS',
    'worker_not_running' => 'Rindas darbinieks: NEDARBOJAS',
    'no_runs' => 'Vēl nav nevienas izpildes. Noklikšķiniet „Izpildīt komandu” vai izmantojiet komandu paleti (⌘K).',
    'recent_runs_aria' => 'Nesenās izpildes',
    'modal_heading' => 'Izpildīt DROŠU komandu',
    'modal_intro' => 'Izvēlieties DROŠĀ līmeņa komandu, ko izpildīt uzreiz. DESTRUKTĪVĀS komandas šeit nav uzskaitītas — izmantojiet laika joslas atkārtotās izpildes pogu vai ⌘K paleti.',
    'args_badge' => 'argumenti',
    'args_badge_title' => 'Atver argumentu formu',

    'spawning_unavailable' => 'Artisan komandas darbojas atsevišķā procesā, un šī platforma neļauj lietotnei tādu palaist. Palaid tās datora lietotnē.',

    'status' => [
        'running' => 'Izpildās',
        'done' => 'Pabeigts',
        'failed' => 'Neizdevās',
        'cancelled' => 'Atcelts',
    ],
    'cancel' => 'Atcelt',
    'rerun' => 'Palaist vēlreiz',
    'started' => 'Sākts :when',
    'exit' => 'izeja',

    'toast' => [
        'unknown_command' => 'Nezināma komanda: :command',
        'missing_args' => 'Nevar izpildīt :command — trūkst :noun: :list',
        'invalid_args' => 'Nevar izpildīt :command — :reason',
        'arg' => 'argumentu|argumenta|argumentu',
        'started' => 'Sākts :command (izpilde :runId)',
        'run_expired' => 'Izpildes ieraksts ir novecojis — nevar palaist vēlreiz.',
        'reran' => 'Atkārtoti izpildīts :command (izpilde :runId)',
        'rerun_forbidden' => 'Šī izpilde pieder citam izstrādātājam.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Izveidot datubāzes dublējumu', 'description' => 'Ieraksta SQLite kopiju ar laika zīmogu dublējumu mapē (vai norādītajā ceļā).'],
        'doctor' => ['label' => 'Palaist doctor', 'description' => 'Ziņo instalētās PHP / Composer / SQLite versijas un pārbauda minimālās prasības.'],
        'failed_jobs' => ['label' => 'Iztīrīt neizdevušos uzdevumus', 'description' => 'Noņem atrisinātos ierakstus no Laravel pārvaldītās tabulas failed_jobs.'],
        'cache_clear' => ['label' => 'Notīrīt kešatmiņu', 'description' => 'Iztukšo lietotnes kešatmiņas krātuvi.'],
        'route_list' => ['label' => 'Uzskaitīt maršrutus', 'description' => 'Izdrukā katru reģistrēto HTTP maršrutu uz stdout.'],
        'config_show' => ['label' => 'Rādīt konfigurāciju', 'description' => 'Izdrukā vērtību norādītajā ar punktiem atdalītajā konfigurācijas atslēgā.'],
        'view_clear' => ['label' => 'Notīrīt veidņu kešatmiņu', 'description' => 'Iztukšo kompilēto Blade veidņu kešatmiņu.'],
        'queue_retry' => ['label' => 'Atkārtot neizdevušos uzdevumus', 'description' => 'Atkārto vienu uzdevumu (pēc id) vai katru neizdevušos uzdevumu (tukšs id).'],
        'rederive_fingerprints' => ['label' => 'Atkārtoti atvasināt nospiedumus', 'description' => 'Pārrēķina katra darījuma nospiedumu ar pašreizējo normalizācijas versiju.'],
        'db_restore' => ['label' => 'Atjaunot datubāzi', 'description' => 'Aizstāj pašreizējo datubāzi ar norādīto dublējuma failu.'],
        'migrate_fresh' => ['label' => 'Nomest tabulas un migrēt no jauna', 'description' => 'Nomet katru tabulu un pēc tam izpilda katru migrāciju no jauna.'],
        'reset_password' => ['label' => 'Atiestatīt paroli', 'description' => 'Interaktīvi atiestata lietotāja paroli (atsakās no neinteraktīvas lietošanas).'],
        'regenerate_recovery_codes' => ['label' => 'Ģenerēt jaunus atkopšanas kodus', 'description' => 'No jauna ģenerē lietotāja 10 vienreiz lietojamos atkopšanas kodus.'],
        'grant_dev' => ['label' => 'Piešķirt izstrādātāja piekļuvi', 'description' => 'Norādītajam lietotājam iestata is_developer=true.'],
        'install' => ['label' => 'Palaist instalēšanu', 'description' => 'Idempotenta pirmās palaišanas uzstādīšana. Atkārtota palaišana jau konfigurētā instalācijā ir destruktīva.'],
    ],

    'arg' => [
        'destination' => ['label' => 'Mērķa fails', 'help' => 'Atstājiet tukšu, lai izmantotu noklusējuma dublējumu mapi.', 'placeholder' => '/ceļš/uz/backup.sqlite (nav obligāts)'],
        'action' => ['label' => 'Darbība'],
        'config' => ['label' => 'Konfigurācijas atslēga', 'help' => 'Konfigurācijas fails vai ar punktiem atdalīta atslēga, ko izdrukāt, piem., `app` vai `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Uzdevuma id', 'help' => 'Atstājiet tukšu, lai atkārtotu katru neizdevušos uzdevumu; norādiet id, lai atkārtotu vienu ierakstu.', 'placeholder' => 'visi (vai konkrēts id)'],
        'queue' => ['label' => 'Rindas nosaukums', 'help' => 'Neobligāts rindas filtrs; pēc noklusējuma visas rindas.', 'placeholder' => 'default'],
        'from' => ['label' => 'Ceļš uz dublējuma failu', 'help' => 'Aizstāj pašreizējo datubāzi ar failu norādītajā ceļā.', 'placeholder' => '/ceļš/uz/backup.sqlite'],
        'username' => ['label' => 'Lietotājvārds', 'placeholder' => 'alice'],
    ],
];
