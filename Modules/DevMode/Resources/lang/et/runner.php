<?php

declare(strict_types=1);

return [
    'heading' => 'Artisani käivitaja',
    'subtitle' => 'Käivita OHUTUD käsud ühe klõpsuga; HÄVITAVAD käsud on kolmekordse kaitse taga.',
    'run_a_command' => 'Käivita käsk',
    'filter_aria' => 'Käivituste filter',
    'filter' => [
        'all' => 'Kõik',
        'running' => 'Töötab',
        'failed' => 'Ebaõnnestunud',
        'destructive' => 'Hävitav',
    ],
    'worker_running' => 'Järjekorra töötaja: TÖÖTAB',
    'worker_not_running' => 'Järjekorra töötaja: EI TÖÖTA',
    'no_runs' => 'Käivitusi veel pole. Klõpsa „Käivita käsk“ või kasuta käsupaletti (⌘K).',
    // i18n-review: et · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Käivitusi veel pole. Puuduta „Käivita käsk“ või kasuta käsupaletti (⌘K).',
    'recent_runs_aria' => 'Hiljutised käivitused',
    'modal_heading' => 'Käivita OHUTU käsk',
    'modal_intro' => 'Vali OHUTU taseme käsk, mis käivitub kohe. HÄVITAVAID käske siin ei loetleta — kasuta ajajoone uuesti käivitamise nuppu või ⌘K paletti.',
    'args_badge' => 'argumendid',
    'args_badge_title' => 'Avab argumentide vormi',

    'spawning_unavailable' => 'Artisani käsud töötavad eraldi protsessis ja see platvorm ei lase rakendusel ühtegi käivitada. Käivita need arvutirakendusest.',

    'status' => [
        'running' => 'Töötab',
        'done' => 'Valmis',
        'failed' => 'Ebaõnnestus',
        'cancelled' => 'Tühistatud',
    ],
    'cancel' => 'Tühista',
    'rerun' => 'Käivita uuesti',
    'started' => 'Alustatud :when',
    'exit' => 'väljumine',

    'toast' => [
        'unknown_command' => 'Tundmatu käsk: :command',
        'missing_args' => 'Käsku :command ei saa käivitada — vaja on :noun: :list',
        'invalid_args' => 'Käsku :command ei saa käivitada — :reason',
        'arg' => 'argument|argumendid',
        'started' => 'Käivitatud :command (käivitus :runId)',
        'run_expired' => 'Käivituse kirje on aegunud — uuesti käivitada ei saa.',
        'reran' => 'Käivitatud uuesti :command (käivitus :runId)',
        'rerun_forbidden' => 'See käivitus kuulub teisele arendajale.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Varunda andmebaas', 'description' => 'Kirjutab ajatempliga SQLite-koopia varukoopiate kausta (või antud asukohta).'],
        'doctor' => ['label' => 'Käivita doctor', 'description' => "Teatab paigaldatud PHP, Composeri ja SQLite'i versioonid ning kontrollib miinimumnõudeid."],
        'failed_jobs' => ['label' => 'Puhasta ebaõnnestunud tööd', 'description' => 'Puhastab lahendatud kirjed Laraveli hallatavast tabelist failed_jobs.'],
        'cache_clear' => ['label' => 'Tühjenda vahemälu', 'description' => 'Tühjendab rakenduse vahemälu.'],
        'route_list' => ['label' => 'Loetle marsruudid', 'description' => 'Väljastab iga registreeritud HTTP-marsruudi standardväljundisse.'],
        'config_show' => ['label' => 'Näita konfiguratsiooni', 'description' => 'Väljastab antud konfiguratsioonivõtme väärtuse.'],
        'view_clear' => ['label' => 'Tühjenda vaadete vahemälu', 'description' => 'Tühjendab kompileeritud Blade-vaadete vahemälu.'],
        'queue_retry' => ['label' => 'Proovi ebaõnnestunud töid uuesti', 'description' => 'Proovib uuesti ühte tööd (ID järgi) või kõiki ebaõnnestunud töid (tühi ID).'],
        'rederive_fingerprints' => ['label' => 'Arvuta sõrmejäljed uuesti', 'description' => 'Arvutab iga tehingu sõrmejälje praeguse normaliseerimisversiooniga uuesti.'],
        'db_restore' => ['label' => 'Taasta andmebaas', 'description' => 'Asendab praeguse andmebaasi antud varukoopiafailiga.'],
        'migrate_fresh' => ['label' => 'Kustuta tabelid ja migreeri uuesti', 'description' => 'Kustutab kõik tabelid ja käivitab seejärel kõik migratsioonid uuesti.'],
        'reset_password' => ['label' => 'Lähtesta parool', 'description' => 'Lähtestab kasutaja parooli interaktiivselt (keeldub mitteinteraktiivsest kasutusest).'],
        'regenerate_recovery_codes' => ['label' => 'Loo taastekoodid uuesti', 'description' => 'Loob kasutaja 10 ühekordset taastekoodi uuesti.'],
        'grant_dev' => ['label' => 'Anna arendaja õigused', 'description' => 'Määrab antud kasutajale is_developer=true.'],
        // i18n-review: et · command.install.description — Idempotentne is a loanword
        // with no settled native form. The sentence relies on the reader knowing
        // the property rather than the word, which a native eye should confirm.
        'install' => ['label' => 'Käivita paigaldus', 'description' => 'Idempotentne esmane seadistus. Selle kordamine seadistatud paigalduses on hävitav.'],
    ],

    'arg' => [
        'destination' => ['label' => 'Sihtfail', 'help' => 'Jäta tühjaks, et kasutada vaikimisi varukoopiate kausta.', 'placeholder' => '/tee/failini/backup.sqlite (valikuline)'],
        'action' => ['label' => 'Toiming'],
        'config' => ['label' => 'Konfiguratsioonivõti', 'help' => 'Väljastatav konfiguratsioonifail või punktidega võti, näiteks `app` või `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Töö ID', 'help' => 'Jäta tühjaks, et proovida uuesti kõiki ebaõnnestunud töid; sisesta ID, et proovida ainult ühte.', 'placeholder' => 'kõik (või kindel ID)'],
        'queue' => ['label' => 'Järjekorra nimi', 'help' => 'Valikuline järjekorra filter; vaikimisi kõik järjekorrad.', 'placeholder' => 'default'],
        'from' => ['label' => 'Varukoopiafaili asukoht', 'help' => 'Asendab praeguse andmebaasi antud asukohas oleva failiga.', 'placeholder' => '/tee/failini/backup.sqlite'],
        'username' => ['label' => 'Kasutajanimi', 'placeholder' => 'alice'],
    ],
];
