<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'O :subject',
        'close' => 'Zavrieť',
    ],

    'page_title' => 'Kde sú moje údaje?',
    'intro' => 'Beatrax ukladá všetko na tomto zariadení. Neexistuje žiadny server Beatraxu ani účet v cloude. Von ide len to, čo si sám pripojíš — poštová schránka, banka cez Enable Banking, zariadenia, ktoré spáruješ na synchronizáciu — a k tomu denný dopyt na výmenné kurzy. Každé pripojenie to povie na obrazovke, kde ho zapínaš.',

    'lives_here' => 'Tvoje údaje sú tu',
    'copy' => 'Kopírovať',
    'copied' => 'Skopírované',

    'location' => [
        'database' => 'Databáza:',
        'artefacts_imports' => 'Naimportované výpisy:',
        'artefacts_mail' => 'Načítaná pošta:',
        'artefacts_drop' => 'Sledovaný priečinok:',
        'backups' => 'Zálohy:',
        'secrets' => 'Prihlasovacie údaje prepojení:',
        'logs' => 'Záznamy:',
    ],

    'copy_aria' => [
        'database' => 'Skopírovať cestu k databáze do schránky',
        'artefacts_imports' => 'Skopírovať cestu k naimportovaným výpisom do schránky',
        'artefacts_mail' => 'Skopírovať cestu k načítanej pošte do schránky',
        'artefacts_drop' => 'Skopírovať cestu k sledovanému priečinku do schránky',
        'backups' => 'Skopírovať cestu k zálohám do schránky',
        'secrets' => 'Skopírovať cestu k prihlasovacím údajom prepojení do schránky',
        'logs' => 'Skopírovať cestu k záznamom do schránky',
    ],

    'artefacts_heading' => 'Tvoje zdrojové dokumenty v zálohe nie sú',
    'artefacts_body' => 'Záloha obsahuje databázu a nič viac. Výpisy, ktoré si naimportoval, pošta, ktorú stiahol skener, aj bločky, ktoré si vložil do sledovaného priečinka, ostávajú tam, kde sú — v troch priečinkoch uvedených vyššie. Odloženie zálohy na bezpečné miesto ich neskopíruje, takže úplný archív znamená vziať aj tieto priečinky — alebo použiť Exportovať všetko nižšie, ktoré ich zabalí spolu so zálohou.',

    'export_heading' => 'Exportovať všetko',
    'export_body' => 'Jeden archív so zašifrovanou kópiou tvojej databázy a s každým zdrojovým dokumentom, ktorý si Beatraxu dal. Rozbaľ ho kdekoľvek a dokumenty v ňom nájdeš presne také, aké boli, v priečinkoch, z ktorých pochádzajú.',
    'export_passphrase_label' => 'Heslo pre databázu',
    'export_confirm_label' => 'Zopakuj heslo',
    'export_passphrase_hint' => 'Databáza v archíve je zašifrovaná týmto heslom a bez neho sa nedá otvoriť, tak si zvoľ také, ktoré si naozaj zapamätáš. Zdrojové dokumenty idú dnu tak, ako sú, takže archív ulož niekam, čomu dôveruješ.',
    'export_cta' => 'Exportovať všetko ako ZIP',
    'export_working' => 'Archív sa vytvára…',

    'delete_heading' => 'Odstránenie tvojich údajov',
    'delete_intro' => 'Tvoje údaje sú súbory na tomto zariadení, takže odstrániť ich znamená odstrániť tieto súbory. Nie je tu tlačidlo, ktoré by to spravilo za teba, a to zámerne: tvoju históriu v skutočnosti drží súborový systém a tlačidlo, ktoré by vyprázdnilo pár tabuliek a súbory nechalo ležať, by bolo horšie než nič.',
    'delete_uninstall' => 'Odinštalovanie Beatraxu tvoje údaje nevymaže. Je to zámer — neúmyselné odinštalovanie nesmie zničiť roky histórie — takže všetko nižšie zostane na tomto zariadení, kým to sám neodstrániš.',
    'delete_list_intro' => 'Ak chceš odstrániť každú stopu, zmaž všetko z tohto:',
    'delete_journal_note' => 'Vedľa databázy ležia dva žurnálové súbory, :wal a :shm. Tvoje najnovšie zmeny sú v nich, kým sa nezapíšu do databázy, tak zmaž všetky tri naraz.',
    'no_telemetry' => 'Nie je tu žiadna telemetria, z ktorej by sa dalo odhlásiť, ani vzdialený účet, ktorý by bolo treba zrušiť.',
];
