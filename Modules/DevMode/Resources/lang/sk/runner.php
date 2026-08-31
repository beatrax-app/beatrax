<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan runner',
    'subtitle' => 'Príkazy SAFE spúšťaj jedným kliknutím; príkazy DESTRUCTIVE sú za trojitou bránou.',
    'run_a_command' => 'Spustiť príkaz',
    'filter_aria' => 'Filter spustení',
    'filter' => [
        'all' => 'Všetky',
        'running' => 'Prebieha',
        'failed' => 'Zlyhané',
        'destructive' => 'Deštruktívne',
    ],
    'worker_running' => 'Queue worker: BEŽÍ',
    'worker_not_running' => 'Queue worker: NEBEŽÍ',
    'no_runs' => 'Zatiaľ žiadne spustenia. Klikni na „Spustiť príkaz“ alebo použi paletu príkazov (⌘K).',
    'recent_runs_aria' => 'Nedávne spustenia',
    'modal_heading' => 'Spustiť príkaz SAFE',
    'modal_intro' => 'Vyber príkaz úrovne SAFE a spusti ho hneď. Príkazy DESTRUCTIVE tu nie sú — použi možnosť opätovného spustenia na časovej osi alebo paletu ⌘K.',
    'args_badge' => 'args',
    'args_badge_title' => 'Otvorí formulár argumentov',

    'spawning_unavailable' => 'Príkazy Artisan bežia v samostatnom procese a táto platforma aplikácii nedovolí žiadny spustiť. Spusti ich z počítačovej aplikácie.',

    'status' => [
        'running' => 'Prebieha',
        'done' => 'Hotovo',
        'failed' => 'Zlyhalo',
        'cancelled' => 'Zrušené',
    ],
    'cancel' => 'Zrušiť',
    'rerun' => 'Spustiť znova',
    'started' => 'Spustené :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Neznámy príkaz: :command',
        'missing_args' => 'Príkaz :command sa nedá spustiť — vyžaduje :noun: :list',
        'invalid_args' => 'Príkaz :command sa nedá spustiť — :reason',
        'arg' => 'argument|argumenty|argumenty',
        'started' => 'Spustené :command (spustenie :runId)',
        'run_expired' => 'Záznam o spustení expiroval — nedá sa spustiť znova.',
        'reran' => 'Znova spustené :command (spustenie :runId)',
        'rerun_forbidden' => 'Toto spustenie patrí inému vývojárovi.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Zálohovať databázu', 'description' => 'Zapíše kópiu SQLite s časovou pečiatkou do priečinka so zálohami (alebo na zadanú cestu).'],
        'doctor' => ['label' => 'Spustiť doctor', 'description' => 'Nahlási nainštalované verzie PHP / Composer / SQLite a overí minimálne požiadavky.'],
        'failed_jobs' => ['label' => 'Vyčistiť zlyhané úlohy', 'description' => 'Odstráni vyriešené záznamy z tabuľky failed_jobs spravovanej Laravelom.'],
        'cache_clear' => ['label' => 'Vymazať vyrovnávaciu pamäť', 'description' => 'Vyprázdni úložisko vyrovnávacej pamäte aplikácie.'],
        // i18n-review: sk · command.route_list — «routa» is borrowed, because
        // «cesta» is already this locale's word for a filesystem path in
        // system.php. A native should confirm the borrowing reads here.
        'route_list' => ['label' => 'Vypísať routy', 'description' => 'Vypíše každú registrovanú HTTP routu na stdout.'],
        'config_show' => ['label' => 'Zobraziť konfiguráciu', 'description' => 'Vypíše hodnotu zadaného konfiguračného kľúča s bodkami.'],
        // i18n-review: sk · command.view_clear — «zobrazenie» is taken by the
        // palette's own views, so the Blade template cache is «pohľady» here to
        // keep the two apart. Confirm that split reads.
        'view_clear' => ['label' => 'Vymazať vyrovnávaciu pamäť pohľadov', 'description' => 'Vyprázdni vyrovnávaciu pamäť skompilovaných pohľadov Blade.'],
        'queue_retry' => ['label' => 'Zopakovať zlyhané úlohy', 'description' => 'Zopakuje jednu úlohu (podľa id) alebo každú zlyhanú úlohu (prázdne id).'],
        'rederive_fingerprints' => ['label' => 'Znova odvodiť odtlačky', 'description' => 'Prepočíta odtlačok každej transakcie s aktuálnou verziou normalizácie.'],
        'db_restore' => ['label' => 'Obnoviť databázu', 'description' => 'Nahradí aktuálnu databázu zadaným súborom zálohy.'],
        'migrate_fresh' => ['label' => 'Odstrániť tabuľky a znova migrovať', 'description' => 'Odstráni každú tabuľku a potom znova spustí každú migráciu.'],
        'reset_password' => ['label' => 'Nastaviť nové heslo', 'description' => 'Interaktívne nastaví nové heslo používateľa (neinteraktívne použitie odmietne).'],
        'regenerate_recovery_codes' => ['label' => 'Znova vygenerovať záložné kódy', 'description' => 'Znova vygeneruje 10 jednorazových záložných kódov používateľa.'],
        'grant_dev' => ['label' => 'Udeliť vývojársky prístup', 'description' => 'Nastaví is_developer=true pre zadaného používateľa.'],
        'install' => ['label' => 'Spustiť inštaláciu', 'description' => 'Idempotentné prvé nastavenie. Opätovné spustenie na nakonfigurovanej inštalácii je deštruktívne.'],
    ],

    'arg' => [
        'destination' => ['label' => 'Cieľový súbor', 'help' => 'Nechaj prázdne a použije sa predvolený priečinok so zálohami.', 'placeholder' => '/cesta/k/backup.sqlite (voliteľné)'],
        'action' => ['label' => 'Akcia'],
        'config' => ['label' => 'Konfiguračný kľúč', 'help' => 'Konfiguračný súbor alebo kľúč s bodkami, ktorý sa má vypísať, napr. `app` alebo `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Id úlohy', 'help' => 'Nechaj prázdne, aby sa zopakovala každá zlyhaná úloha; zadaním id zopakuješ jediný záznam.', 'placeholder' => 'všetky (alebo konkrétne id)'],
        'queue' => ['label' => 'Názov frontu', 'help' => 'Voliteľný filter podľa frontu; predvolene všetky fronty.', 'placeholder' => 'default'],
        'from' => ['label' => 'Cesta k súboru zálohy', 'help' => 'Nahradí aktuálnu databázu súborom na zadanej ceste.', 'placeholder' => '/cesta/k/backup.sqlite'],
        'username' => ['label' => 'Používateľské meno', 'placeholder' => 'alice'],
    ],
];
