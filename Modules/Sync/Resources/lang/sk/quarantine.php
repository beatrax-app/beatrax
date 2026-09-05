<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count zmenu vytvorila novšia verzia aplikácie Beatrax|:count zmeny vytvorila novšia verzia aplikácie Beatrax|:count zmien vytvorila novšia verzia aplikácie Beatrax',
        'body' => 'To, čo bolo odmietnuté, odkazuje na niečo, čo táto verzia aplikácie Beatrax nemá, takže to toto zariadenie nemalo kam uložiť. Zostáva to na zariadení, ktoré to vytvorilo, a nič z toho, čo je tvoje, sa nezmazalo.',
        'action' => 'Aktualizuj Beatrax na tomto zariadení. Zmeny vykonané po aktualizácii prídu normálne, ale nič, čo už bolo odmietnuté, sa znovu neposiela — ak zmenu potrebuješ aj na tomto zariadení, vykonaj ju tu ešte raz.',
    ],
    'untrusted_author' => [
        'summary' => ':count zmenu podpísalo zariadenie, ktoré toto zariadenie nepozná|:count zmeny podpísalo zariadenie, ktoré toto zariadenie nepozná|:count zmien podpísalo zariadenie, ktoré toto zariadenie nepozná',
        'body' => 'To, čo bolo odmietnuté, prišlo zo zariadenia, ktoré s týmto nikdy nebolo spárované, alebo zo zariadenia, ktoré si odstránil. Nič sa sem nezapísalo a nič z toho, čo tu už bolo, sa nezmenilo.',
        'action' => 'Ak si to zariadenie odstránil sám, presne toto odstránenie robí a nie je čo opravovať. Ak nie, pozri sa na zoznam zariadení na tejto stránke.',
    ],
    'not_verified' => [
        'summary' => ':count zmena neprešla bezpečnostnou kontrolou na tomto zariadení|:count zmeny neprešli bezpečnostnou kontrolou na tomto zariadení|:count zmien neprešlo bezpečnostnou kontrolou na tomto zariadení',
        'body' => 'Podpis nezodpovedal zariadeniu, ktoré tvrdilo, že zmenu vykonalo, alebo bola zmena adresovaná inému účtu. Nič sa sem nezapísalo. Medzi tvojimi vlastnými zariadeniami by k tomu dochádzať nemalo.',
        'action' => 'Pozri sa na zoznam zariadení na tejto stránke a odstráň všetko, čo nepoznáš. Ak je každé zariadenie v zozname tvoje a deje sa to ďalej, ide o chybu v Beatraxe, nie o niečo, čo by si odtiaľto mohol napraviť.',
    ],
    'diverged' => [
        'summary' => ':count zmena z iného zariadenia sa sem nedala uložiť|:count zmeny z iného zariadenia sa sem nedali uložiť|:count zmien z iného zariadenia sa sem nedalo uložiť',
        'body' => 'Prišlo niečo, čo toto zariadenie nedokázalo uložiť: záznam, ktorému chýba časť jeho samého, dátum, ktorý neexistuje, rozdelenie, ktoré už nesedí, záznam, ktorému dve zariadenia už priradili rovnakú identitu, alebo zmazanie niečoho, čo sa tu ešte používa. To, čo bolo odmietnuté, je na tvojom druhom zariadení a na tomto nie, takže obe zariadenia už neobsahujú to isté.',
        'action' => 'Porovnaj záznam na svojom druhom zariadení s tým, čo vidíš tu, a vykonaj zmenu tu ešte raz — alebo ju tu znovu zmaž, ak je tu stále niečo, čo si odstránil inde. Nič odmietnuté sa samo od seba znovu neposiela.',
    ],
    'last_seen' => 'Najnovšie: :when',
];
