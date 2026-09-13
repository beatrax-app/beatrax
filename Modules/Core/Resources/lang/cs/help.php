<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'O :subject',
        'close' => 'Zavřít',
    ],

    'page_title' => 'Kde jsou moje data?',
    'intro' => 'Beatrax ukládá všechno na tomto zařízení. Neexistuje žádný server Beatraxu ani účet v cloudu. Sám od sebe odchází jediný požadavek — kontrola nové verze, kterou můžeš vypnout. Všechno ostatní čeká na tebe: poštovní schránka, banka přes Enable Banking, denní dotaz na směnné kurzy, zařízení, která spáruješ pro synchronizaci, relay, který si nastavíš, a každý odkaz, na který klikneš. Každé z nich to říká na obrazovce, kde ho zapínáš.',

    'lives_here' => 'Tvoje data jsou tady',
    'copy' => 'Kopírovat',
    'copied' => 'Zkopírováno',

    'location' => [
        'database' => 'Databáze:',
        'artifacts_imports' => 'Naimportované výpisy:',
        'artifacts_mail' => 'Načtená pošta:',
        'artifacts_drop' => 'Sledovaná složka:',
        'backups' => 'Zálohy:',
        'secrets' => 'Přihlašovací údaje propojení:',
        'logs' => 'Protokoly:',
    ],

    'copy_aria' => [
        'database' => 'Zkopírovat cestu k databázi do schránky',
        'artifacts_imports' => 'Zkopírovat cestu k naimportovaným výpisům do schránky',
        'artifacts_mail' => 'Zkopírovat cestu k načtené poště do schránky',
        'artifacts_drop' => 'Zkopírovat cestu ke sledované složce do schránky',
        'backups' => 'Zkopírovat cestu k zálohám do schránky',
        'secrets' => 'Zkopírovat cestu k přihlašovacím údajům propojení do schránky',
        'logs' => 'Zkopírovat cestu k protokolům do schránky',
    ],

    'artifacts_heading' => 'Tvoje zdrojové dokumenty v záloze nejsou',
    'artifacts_body' => 'Záloha obsahuje databázi a nic víc. Výpisy, které jsi naimportoval, pošta, kterou stáhl skener, i účtenky, které jsi vložil do sledované složky, zůstávají tam, kde jsou — ve třech složkách vypsaných výše. Uložením zálohy na bezpečné místo se nezkopírují, takže úplný archiv znamená vzít i tyhle složky — nebo použít Exportovat všechno níže, které je zabalí spolu se zálohou.',

    'export_heading' => 'Exportovat všechno',
    'export_body' => 'Jediný archiv se zašifrovanou kopií tvojí databáze a s každým zdrojovým dokumentem, který jsi Beatraxu dal. Rozbal ho kdekoli a dokumenty v něm najdeš přesně takové, jaké byly, ve složkách, ze kterých pocházejí.',
    'export_passphrase_label' => 'Heslo pro databázi',
    'export_confirm_label' => 'Zopakuj heslo',
    'export_passphrase_hint' => 'Databáze uvnitř archivu je zašifrovaná tímto heslem a bez něj ji nejde otevřít, tak si vyber takové, které si opravdu uchováš. Zdrojové dokumenty jdou dovnitř tak, jak jsou, takže archiv ulož někam, čemu věříš.',
    'export_cta' => 'Exportovat všechno jako ZIP',
    'export_working' => 'Archiv se vytváří…',

    'delete_heading' => 'Smazání tvých dat',
    'delete_intro' => 'Tvoje data jsou soubory na tomhle zařízení, takže smazat je znamená smazat ty soubory. Není tu tlačítko, které by to udělalo za tebe, a to schválně: tvoji historii drží souborový systém a tlačítko, které by vyprázdnilo pár tabulek a soubory nechalo ležet, by bylo horší než nic.',
    'delete_uninstall' => 'Odinstalování Beatraxu tvoje data nesmaže. Je to záměr — nechtěná odinstalace nesmí zničit roky historie — takže všechno níže zůstane na tomhle zařízení, dokud to sám neodstraníš.',
    'delete_list_intro' => 'Když chceš smazat každou stopu, smaž všechno z tohohle:',
    'delete_journal_note' => 'Vedle databáze leží dva žurnálové soubory, :wal a :shm. Tvoje nejnovější změny jsou v nich, dokud se nezapíšou do databáze, takže smaž všechny tři najednou.',
    'no_telemetry' => 'Není z čeho se odhlašovat — žádná telemetrie ani vzdálený účet, který by šlo zrušit.',

    /** @link ../../../../../.docs/features/budgets/moving-the-budget-month.md#the-mapping-keep-the-distance-from-the-month-the-reader-is-in */
    'period' => 'Úsek času, přes který se měří tvé rozpočty, tvůj přehled a každé číslo pro „toto období“. „:label“ určuje, kde začíná — nastav ho na den po výplatě a období bude držet peníze, které ho měly pokrýt. Posunutí toho dne přeřadí každou částku, kterou už máš v obálkách rozdělenou, a kde se dvě stará období složí na jedno nové, jejich částky se sečtou; posunutí dne zpět je zase nerozdělí.',

    /** @link ../../../../../.docs/features/position/architecture.md#composition-never-a-raw-select */
    'net_worth' => 'Všechno, o čem Beatrax ví, že to máš, minus všechno, co dlužíš: zůstatek každého účtu, který jsi naimportoval nebo připojil, přičemž zůstatky karet a půjček se počítají proti tobě. Úplnější než to, co jsi mu dal, to není — účet, který Beatrax nikdy neviděl, v tomhle čísle není. Zůstatky v jiné měně se přepočítají kurzem uvedeným pod číslem a to, co ocenit nešlo, je tam pojmenované, místo aby potichu vypadlo.',
];
