<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan runner',
    'subtitle' => 'A SAFE parancsokat egy kattintással futtathatod; a DESTRUCTIVE parancsok a hármas zár mögött vannak.',
    'run_a_command' => 'Parancs futtatása',
    'filter_aria' => 'Futtatásszűrő',
    'filter' => [
        'all' => 'Összes',
        'running' => 'Fut',
        'failed' => 'Sikertelen',
        'destructive' => 'Destruktív',
    ],
    'worker_running' => 'Várólista-worker: FUT',
    'worker_not_running' => 'Várólista-worker: NEM FUT',
    'no_runs' => 'Még nincs futtatás. Kattints a "Parancs futtatása" gombra, vagy használd a parancspalettát (⌘K).',
    // i18n-review: hu · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Még nincs futtatás. Koppints a "Parancs futtatása" gombra, vagy használd a parancspalettát (⌘K).',
    'recent_runs_aria' => 'Legutóbbi futtatások',
    'modal_heading' => 'SAFE parancs futtatása',
    'modal_intro' => 'Válassz egy SAFE szintű parancsot az azonnali futtatáshoz. A DESTRUCTIVE parancsok itt nem szerepelnek — használd az idővonal újrafuttatás lehetőségét vagy a ⌘K palettát.',
    'args_badge' => 'args',
    'args_badge_title' => 'Argumentum-űrlapot nyit meg',

    'spawning_unavailable' => 'Az Artisan-parancsok külön folyamatban futnak, és ez a platform nem engedi az alkalmazásnak, hogy elindítson egyet. Futtasd őket az asztali alkalmazásból.',

    'status' => [
        'running' => 'Fut',
        'done' => 'Kész',
        'failed' => 'Sikertelen',
        'cancelled' => 'Megszakítva',
    ],
    'cancel' => 'Mégse',
    'rerun' => 'Újrafuttatás',
    'started' => 'Elindítva :when',
    'exit' => 'kilépési kód',

    'toast' => [
        'unknown_command' => 'Ismeretlen parancs: :command',
        'missing_args' => 'A(z) :command nem futtatható — hiányzó :noun: :list',
        'invalid_args' => 'A(z) :command nem futtatható — :reason',
        'arg' => 'argumentum|argumentumok',
        'started' => 'Elindítva: :command (futtatás: :runId)',
        'run_expired' => 'A futtatási bejegyzés lejárt — nem lehet újrafuttatni.',
        'reran' => 'Újrafuttatva: :command (futtatás: :runId)',
        'rerun_forbidden' => 'Ez a futtatás egy másik fejlesztőé.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Adatbázis mentése', 'description' => 'Időbélyeggel ellátott SQLite-másolatot ír a mentések könyvtárába.'],
        'doctor' => ['label' => 'Doctor futtatása', 'description' => 'Jelenti a telepített PHP-, Composer- és SQLite-verziókat, és ellenőrzi a minimumokat.'],
        'failed_jobs' => ['label' => 'Sikertelen feladatok tisztítása', 'description' => 'Kitisztítja a lezárt bejegyzéseket a Laravel kezelte failed_jobs táblából.'],
        'cache_clear' => ['label' => 'Gyorsítótár ürítése', 'description' => 'Kiüríti az alkalmazás gyorsítótárát.'],
        'route_list' => ['label' => 'Útvonalak listázása', 'description' => 'Kiírja a szabványos kimenetre az összes regisztrált HTTP-útvonalat.'],
        'config_show' => ['label' => 'Konfiguráció megjelenítése', 'description' => 'Kiírja a megadott konfigurációs kulcs értékét.'],
        'view_clear' => ['label' => 'Nézet-gyorsítótár ürítése', 'description' => 'Kiüríti a lefordított Blade-nézetek gyorsítótárát.'],
        'queue_retry' => ['label' => 'Sikertelen feladatok újrapróbálása', 'description' => 'Újrapróbál egy feladatot (azonosító alapján) vagy az összes sikertelent (üres azonosító).'],
        'rederive_fingerprints' => ['label' => 'Ujjlenyomatok újraszámítása', 'description' => 'Újraszámítja minden tranzakció ujjlenyomatát a jelenlegi normalizálási verzióval.'],
        'db_restore' => ['label' => 'Adatbázis visszaállítása', 'description' => 'Lecseréli a jelenlegi adatbázist a megadott mentésfájlra.'],
        'regenerate_recovery_codes' => ['label' => 'Helyreállítási kódok újragenerálása', 'description' => 'Újragenerálja egy felhasználó 10 egyszer használható helyreállítási kódját.'],
        'grant_dev' => ['label' => 'Fejlesztői hozzáférés megadása', 'description' => 'Az is_developer értékét true-ra állítja a megadott felhasználónál.'],
        'install' => ['label' => 'Telepítés futtatása', 'description' => 'Idempotens első beállítás. Beállított telepítésen újrafuttatva destruktív.'],
    ],

    'arg' => [
        'action' => ['label' => 'Művelet'],
        'config' => ['label' => 'Konfigurációs kulcs', 'help' => 'A kiírandó konfigurációs fájl vagy pontokkal tagolt kulcs, például `app` vagy `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Feladatazonosító', 'help' => 'Hagyd üresen az összes sikertelen feladat újrapróbálásához; adj meg azonosítót egyetlen bejegyzéshez.', 'placeholder' => 'mind (vagy egy adott azonosító)'],
        'queue' => ['label' => 'Várólista neve', 'help' => 'Nem kötelező várólista-szűrő; alapértelmezetten minden várólista.', 'placeholder' => 'default'],
        'path' => ['label' => 'A mentésfájl elérési útja', 'help' => 'Lecseréli a jelenlegi adatbázist a megadott útvonalon lévő fájlra.', 'placeholder' => '/eleresi/ut/backup.sqlite'],
        'username' => ['label' => 'Felhasználónév', 'placeholder' => 'alice'],
    ],
];
