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
    // i18n-review: lv · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Vēl nav nevienas izpildes. Pieskarieties „Izpildīt komandu” vai izmantojiet komandu paleti (⌘K).',
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
        'db_backup' => ['label' => 'Izveidot datubāzes dublējumu', 'description' => 'Ieraksta SQLite kopiju ar laika zīmogu dublējumu mapē, ja vien datubāze kopš pēdējā dublējuma nav palikusi nemainīga. Paturēta kopija noņem arī vecākos dublējumus atbilstoši glabāšanas politikai.'],
        'doctor' => ['label' => 'Palaist doctor', 'description' => 'Palaiž operacionālo pārbaužu komplektu un katrai rindai ziņo pass / warn / fail. Rinda warn vai fail dod izejas kodu, kas nav nulle.'],
        'failed_jobs' => ['label' => 'Iztīrīt neizdevušos uzdevumus', 'description' => 'No Laravel pārvaldītās tabulas failed_jobs izdzēš katru ierakstu, kas vecāks par 30 dienām, neatkarīgi no tā, vai uzdevums jebkad tika atkārtots.'],
        'cache_clear' => ['label' => 'Notīrīt kešatmiņu', 'description' => 'Iztukšo lietotnes kešatmiņas krātuvi.'],
        'route_list' => ['label' => 'Uzskaitīt maršrutus', 'description' => 'Izdrukā katru reģistrēto HTTP maršrutu uz stdout.'],
        'config_show' => ['label' => 'Rādīt konfigurāciju', 'description' => 'Izdrukā visu konfigurācijas failu vai tajā esošas ar punktiem atdalītas atslēgas vērtību.'],
        'view_clear' => ['label' => 'Notīrīt veidņu kešatmiņu', 'description' => 'Iztukšo kompilēto Blade veidņu kešatmiņu.'],
        'queue_retry' => ['label' => 'Atkārtot neizdevušos uzdevumus', 'description' => 'Atkārto vienu neizdevušos uzdevumu pēc id vai visus, ja norādāt `all`.'],
        'rederive_fingerprints' => ['label' => 'Atkārtoti atvasināt nospiedumus', 'description' => 'Pārrēķina nospiedumu katram darījumam, kas joprojām ir zem pašreizējās normalizācijas versijas. No šejienes palaista komanda ziņo skaitu un neko neieraksta.'],
        'demo_seed' => ['label' => 'Ielādēt paraugdatus', 'description' => 'Pievieno parauga grāmatu — kontus, darījumus, budžetus, mērķus un brīdinājumus — izdomātu, lai lietotni varētu apskatīt ar kaut ko iekšā. Tā pievienojas jau esošajam, nevis to aizstāj, un nekas no tā nav reālas personas dati.'],
        'db_restore' => ['label' => 'Atjaunot datubāzi', 'description' => 'Aizstāj pašreizējo datubāzi ar norādīto dublējuma failu.'],
        'regenerate_recovery_codes' => ['label' => 'Ģenerēt jaunus atkopšanas kodus', 'description' => 'No jauna ģenerē lietotāja 10 vienreiz lietojamos atkopšanas kodus.'],
        'grant_dev' => ['label' => 'Piešķirt izstrādātāja piekļuvi', 'description' => 'Norādītajam lietotājam iestata is_developer=true.'],
        'install' => ['label' => 'Palaist instalēšanu', 'description' => 'Idempotenta pirmās palaišanas uzstādīšana: datubāzes shēma, atsauces dati un vienīgais lietotāja konts. Atkārtota palaišana jau konfigurētā instalācijā no jauna apstiprina esošo kontu un atstāj paroli nemainītu.'],
    ],

    'arg' => [
        'action' => ['label' => 'Darbība'],
        'config' => ['label' => 'Konfigurācijas atslēga', 'help' => 'Konfigurācijas fails vai ar punktiem atdalīta atslēga, ko izdrukāt, piem., `app` vai `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Uzdevuma id', 'help' => 'Ierakstiet `all`, lai atkārtotu katru neizdevušos uzdevumu, vai uzdevuma id, lai atkārtotu vienu ierakstu. Tukšs lauks neatkārto neko.', 'placeholder' => 'all (vai konkrēts id)'],
        'queue' => ['label' => 'Rindas nosaukums', 'help' => 'Neobligāts rindas filtrs; pēc noklusējuma visas rindas.', 'placeholder' => 'default'],
        'path' => ['label' => 'Ceļš uz dublējuma failu', 'help' => 'Aizstāj pašreizējo datubāzi ar failu norādītajā ceļā.', 'placeholder' => '/ceļš/uz/backup.sqlite'],
        'username' => ['label' => 'Lietotājvārds', 'placeholder' => 'alice'],
    ],
];
