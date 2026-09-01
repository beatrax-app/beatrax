<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan runner',
    'subtitle' => 'Příkazy SAFE spouštěj jedním kliknutím; příkazy DESTRUCTIVE jsou za trojitou pojistkou.',
    'run_a_command' => 'Spustit příkaz',
    'filter_aria' => 'Filtr spuštění',
    'filter' => [
        'all' => 'Vše',
        'running' => 'Běží',
        'failed' => 'Selhalo',
        'destructive' => 'Destruktivní',
    ],
    'worker_running' => 'Worker fronty: BĚŽÍ',
    'worker_not_running' => 'Worker fronty: NEBĚŽÍ',
    'no_runs' => 'Zatím žádná spuštění. Klikni na „Spustit příkaz“ nebo použij paletu příkazů (⌘K).',
    // i18n-review: cs · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Zatím žádná spuštění. Klepni na „Spustit příkaz“ nebo použij paletu příkazů (⌘K).',
    'recent_runs_aria' => 'Nedávná spuštění',
    'modal_heading' => 'Spustit příkaz SAFE',
    'modal_intro' => 'Vyber příkaz úrovně SAFE a spusť ho hned. Příkazy DESTRUCTIVE tady nejsou — použij opětovné spuštění v časové ose nebo paletu ⌘K.',
    'args_badge' => 'args',
    'args_badge_title' => 'Otevře formulář argumentů',

    'spawning_unavailable' => 'Příkazy Artisan běží v samostatném procesu a tato platforma aplikaci nedovolí žádný spustit. Spusť je z desktopové aplikace.',

    'status' => [
        'running' => 'Běží',
        'done' => 'Hotovo',
        'failed' => 'Selhalo',
        'cancelled' => 'Zrušeno',
    ],
    'cancel' => 'Zrušit',
    'rerun' => 'Spustit znovu',
    'started' => 'Spuštěno :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Neznámý příkaz: :command',
        'missing_args' => 'Nelze spustit :command — chybí :noun: :list',
        'invalid_args' => 'Nelze spustit :command — :reason',
        'arg' => 'argument|argumenty|argumenty',
        'started' => 'Spuštěno :command (běh :runId)',
        'run_expired' => 'Záznam o spuštění vypršel — nelze spustit znovu.',
        'reran' => 'Znovu spuštěno :command (běh :runId)',
        'rerun_forbidden' => 'Tento běh patří jinému vývojáři.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Zálohovat databázi', 'description' => 'Zapíše kopii SQLite s časovým razítkem do adresáře záloh (nebo na zadanou cestu).'],
        'doctor' => ['label' => 'Spustit doctor', 'description' => 'Nahlásí nainstalované verze PHP / Composeru / SQLite a ověří minima.'],
        'failed_jobs' => ['label' => 'Vyčistit neúspěšné úlohy', 'description' => 'Odstraní vyřešené záznamy z tabulky failed_jobs spravované Laravelem.'],
        'cache_clear' => ['label' => 'Vymazat mezipaměť', 'description' => 'Vyprázdní úložiště mezipaměti aplikace.'],
        'route_list' => ['label' => 'Vypsat routy', 'description' => 'Vypíše každou registrovanou HTTP routu na stdout.'],
        'config_show' => ['label' => 'Zobrazit konfiguraci', 'description' => 'Vypíše hodnotu na zadaném tečkovém konfiguračním klíči.'],
        'view_clear' => ['label' => 'Vymazat mezipaměť šablon', 'description' => 'Vyprázdní mezipaměť zkompilovaných šablon Blade.'],
        'queue_retry' => ['label' => 'Zopakovat neúspěšné úlohy', 'description' => 'Zopakuje jednu úlohu (podle id) nebo každou neúspěšnou úlohu (prázdné id).'],
        'rederive_fingerprints' => ['label' => 'Znovu odvodit otisky', 'description' => 'Přepočítá otisk každé transakce podle aktuální verze normalizace.'],
        'db_restore' => ['label' => 'Obnovit databázi', 'description' => 'Nahradí současnou databázi zadaným souborem zálohy.'],
        'migrate_fresh' => ['label' => 'Zahodit tabulky a migrovat znovu', 'description' => 'Zahodí každou tabulku a pak spustí znovu každou migraci.'],
        'reset_password' => ['label' => 'Resetovat heslo', 'description' => 'Interaktivně resetuje heslo uživatele (neinteraktivní použití odmítne).'],
        'regenerate_recovery_codes' => ['label' => 'Vygenerovat nové záložní kódy', 'description' => 'Vygeneruje uživateli znovu 10 jednorázových záložních kódů.'],
        'grant_dev' => ['label' => 'Udělit vývojářský přístup', 'description' => 'Nastaví zadanému uživateli is_developer=true.'],
        'install' => ['label' => 'Spustit instalaci', 'description' => 'Idempotentní prvotní nastavení. Opětovné spuštění na nakonfigurované instalaci je destruktivní.'],
    ],

    'arg' => [
        'destination' => ['label' => 'Cílový soubor', 'help' => 'Nech prázdné, ať se použije výchozí adresář záloh.', 'placeholder' => '/cesta/k/backup.sqlite (nepovinné)'],
        'action' => ['label' => 'Akce'],
        'config' => ['label' => 'Konfigurační klíč', 'help' => 'Konfigurační soubor nebo tečkový klíč, který se má vypsat, např. `app` nebo `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Id úlohy', 'help' => 'Nech prázdné, ať se zopakuje každá neúspěšná úloha; zadej id, ať se zopakuje jeden záznam.', 'placeholder' => 'vše (nebo konkrétní id)'],
        'queue' => ['label' => 'Název fronty', 'help' => 'Nepovinný filtr fronty; výchozí jsou všechny fronty.', 'placeholder' => 'default'],
        'from' => ['label' => 'Cesta k souboru zálohy', 'help' => 'Nahradí současnou databázi souborem na zadané cestě.', 'placeholder' => '/cesta/k/backup.sqlite'],
        'username' => ['label' => 'Uživatelské jméno', 'placeholder' => 'alice'],
    ],
];
