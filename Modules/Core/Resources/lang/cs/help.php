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
        'artefacts_imports' => 'Naimportované výpisy:',
        'artefacts_mail' => 'Načtená pošta:',
        'artefacts_drop' => 'Sledovaná složka:',
        'backups' => 'Zálohy:',
        'secrets' => 'Přihlašovací údaje propojení:',
        'logs' => 'Protokoly:',
    ],

    'copy_aria' => [
        'database' => 'Zkopírovat cestu k databázi do schránky',
        'artefacts_imports' => 'Zkopírovat cestu k naimportovaným výpisům do schránky',
        'artefacts_mail' => 'Zkopírovat cestu k načtené poště do schránky',
        'artefacts_drop' => 'Zkopírovat cestu ke sledované složce do schránky',
        'backups' => 'Zkopírovat cestu k zálohám do schránky',
        'secrets' => 'Zkopírovat cestu k přihlašovacím údajům propojení do schránky',
        'logs' => 'Zkopírovat cestu k protokolům do schránky',
    ],

    'artefacts_heading' => 'Tvoje zdrojové dokumenty v záloze nejsou',
    'artefacts_body' => 'Záloha obsahuje databázi a nic víc. Výpisy, které jsi naimportoval, pošta, kterou stáhl skener, i účtenky, které jsi vložil do sledované složky, zůstávají tam, kde jsou — ve třech složkách vypsaných výše. Uložením zálohy na bezpečné místo se nezkopírují, takže úplný archiv znamená vzít i tyhle složky — nebo použít Exportovat všechno níže, které je zabalí spolu se zálohou.',

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
];
