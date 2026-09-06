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
        'db_backup' => ['label' => 'Varunda andmebaas', 'description' => 'Kirjutab ajatempliga SQLite-koopia varukoopiate kausta, välja arvatud siis, kui andmebaas pole pärast eelmist koopiat muutunud. Alles jäetud koopia kustutab ka vanemad varukoopiad säilituspoliitika järgi.'],
        'doctor' => ['label' => 'Käivita doctor', 'description' => 'Käivitab operatiivsete kontrollide komplekti ja teatab iga rea kohta pass / warn / fail. Warn- või fail-rida annab nullist erineva väljumiskoodi.'],
        'failed_jobs' => ['label' => 'Puhasta ebaõnnestunud tööd', 'description' => 'Kustutab Laraveli hallatavast tabelist failed_jobs iga kirje, mis on vanem kui 30 päeva, olenemata sellest, kas tööd kunagi uuesti prooviti.'],
        'cache_clear' => ['label' => 'Tühjenda vahemälu', 'description' => 'Tühjendab rakenduse vahemälu.'],
        'route_list' => ['label' => 'Loetle marsruudid', 'description' => 'Väljastab iga registreeritud HTTP-marsruudi standardväljundisse.'],
        'config_show' => ['label' => 'Näita konfiguratsiooni', 'description' => 'Väljastab terve konfiguratsioonifaili või selles oleva punktidega võtme väärtuse.'],
        'view_clear' => ['label' => 'Tühjenda vaadete vahemälu', 'description' => 'Tühjendab kompileeritud Blade-vaadete vahemälu.'],
        'queue_retry' => ['label' => 'Proovi ebaõnnestunud töid uuesti', 'description' => 'Proovib ID järgi uuesti ühte ebaõnnestunud tööd või kõiki, kui sisestad `all`.'],
        'rederive_fingerprints' => ['label' => 'Arvuta sõrmejäljed uuesti', 'description' => 'Arvutab uuesti sõrmejälje igale tehingule, mille normaliseerimisversioon on veel praegusest väiksem. Siit käivitatud jooks teatab arvu ega kirjuta midagi.'],
        'demo_seed' => ['label' => 'Laadi näidisandmed', 'description' => 'Lisab näidisraamatu — kontod, tehingud, eelarved, eesmärgid ja teated — välja mõeldud selleks, et näha rakendust millegagi sees. See lisandub olemasolevale, mitte ei asenda seda, ja miski sellest pole tegeliku inimese andmed.'],
        'db_restore' => ['label' => 'Taasta andmebaas', 'description' => 'Asendab praeguse andmebaasi antud varukoopiafailiga.'],
        'regenerate_recovery_codes' => ['label' => 'Loo taastekoodid uuesti', 'description' => 'Loob kasutaja 10 ühekordset taastekoodi uuesti.'],
        'grant_dev' => ['label' => 'Anna arendaja õigused', 'description' => 'Määrab antud kasutajale is_developer=true.'],
        // i18n-review: et · command.install.description — Idempotentne is a loanword
        // with no settled native form. The sentence relies on the reader knowing
        // the property rather than the word, which a native eye should confirm.
        'install' => ['label' => 'Käivita paigaldus', 'description' => 'Idempotentne esmane seadistus: andmebaasi skeem, viiteandmed ja ainus kasutajakonto. Kordamine seadistatud paigalduses kinnitab olemasoleva konto uuesti ja jätab parooli muutmata.'],
    ],

    'arg' => [
        'action' => ['label' => 'Toiming'],
        'config' => ['label' => 'Konfiguratsioonivõti', 'help' => 'Väljastatav konfiguratsioonifail või punktidega võti, näiteks `app` või `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Töö ID', 'help' => 'Kirjuta `all`, et proovida uuesti kõiki ebaõnnestunud töid, või töö ID, et proovida ainult ühte. Tühi väli ei proovi midagi uuesti.', 'placeholder' => 'all (või kindel ID)'],
        'queue' => ['label' => 'Järjekorra nimi', 'help' => 'Valikuline järjekorra filter; vaikimisi kõik järjekorrad.', 'placeholder' => 'default'],
        'path' => ['label' => 'Varukoopiafaili asukoht', 'help' => 'Asendab praeguse andmebaasi antud asukohas oleva failiga.', 'placeholder' => '/tee/failini/backup.sqlite'],
        'username' => ['label' => 'Kasutajanimi', 'placeholder' => 'alice'],
    ],
];
