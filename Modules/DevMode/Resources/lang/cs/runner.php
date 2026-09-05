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
        'db_backup' => ['label' => 'Zálohovat databázi', 'description' => 'Zapíše kopii SQLite s časovým razítkem do adresáře záloh, ledaže se databáze od poslední zálohy nezměnila. Ponechaná kopie navíc odstraní starší zálohy podle pravidel uchovávání.'],
        'doctor' => ['label' => 'Spustit doctor', 'description' => 'Spustí sadu provozních sond a nahlásí pass / warn / fail pro každý řádek. Řádek warn nebo fail znamená nenulový návratový kód.'],
        'failed_jobs' => ['label' => 'Vyčistit neúspěšné úlohy', 'description' => 'Smaže z tabulky failed_jobs spravované Laravelem každý řádek starší než 30 dní, ať už byla úloha zopakována, nebo ne.'],
        'cache_clear' => ['label' => 'Vymazat mezipaměť', 'description' => 'Vyprázdní úložiště mezipaměti aplikace.'],
        'route_list' => ['label' => 'Vypsat routy', 'description' => 'Vypíše každou registrovanou HTTP routu na stdout.'],
        'config_show' => ['label' => 'Zobrazit konfiguraci', 'description' => 'Vypíše celý konfigurační soubor nebo hodnotu tečkového klíče v něm.'],
        'view_clear' => ['label' => 'Vymazat mezipaměť šablon', 'description' => 'Vyprázdní mezipaměť zkompilovaných šablon Blade.'],
        'queue_retry' => ['label' => 'Zopakovat neúspěšné úlohy', 'description' => 'Zopakuje jednu neúspěšnou úlohu podle id, nebo každou neúspěšnou úlohu, když zadáš `all`.'],
        'rederive_fingerprints' => ['label' => 'Znovu odvodit otisky', 'description' => 'Přepočítá otisk každé transakce, která je stále pod aktuální verzí normalizace. Spuštění odsud nahlásí počet a nic nezapíše.'],
        'db_restore' => ['label' => 'Obnovit databázi', 'description' => 'Nahradí současnou databázi zadaným souborem zálohy.'],
        'regenerate_recovery_codes' => ['label' => 'Vygenerovat nové záložní kódy', 'description' => 'Vygeneruje uživateli znovu 10 jednorázových záložních kódů.'],
        'grant_dev' => ['label' => 'Udělit vývojářský přístup', 'description' => 'Nastaví zadanému uživateli is_developer=true.'],
        'install' => ['label' => 'Spustit instalaci', 'description' => 'Idempotentní prvotní nastavení: schéma databáze, referenční data a jediný uživatelský účet. Opětovné spuštění na nakonfigurované instalaci znovu potvrdí stávající účet a heslo nechá beze změny.'],
    ],

    'arg' => [
        'action' => ['label' => 'Akce'],
        'config' => ['label' => 'Konfigurační klíč', 'help' => 'Konfigurační soubor nebo tečkový klíč, který se má vypsat, např. `app` nebo `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Id úlohy', 'help' => 'Zadej `all`, ať se zopakuje každá neúspěšná úloha, nebo id úlohy, ať se zopakuje jeden záznam. Prázdné pole nezopakuje nic.', 'placeholder' => 'all (nebo konkrétní id)'],
        'queue' => ['label' => 'Název fronty', 'help' => 'Nepovinný filtr fronty; výchozí jsou všechny fronty.', 'placeholder' => 'default'],
        'path' => ['label' => 'Cesta k souboru zálohy', 'help' => 'Nahradí současnou databázi souborem na zadané cestě.', 'placeholder' => '/cesta/k/backup.sqlite'],
        'username' => ['label' => 'Uživatelské jméno', 'placeholder' => 'alice'],
    ],
];
