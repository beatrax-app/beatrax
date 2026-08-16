<?php

declare(strict_types=1);

return [
    'about_heading' => 'O zdieľanom zozname',
    'about_body' => 'Priložený YAML súbor, ktorý mapuje kryptické kódy z bankových výpisov na zrozumiteľné mená obchodníkov. Po zapnutí môže Beatrax tento zoznam čítať pri importe; odoslanie návrhu otvorí GitHub v prehliadači.',

    'mappings' => 'Mapovania',
    'contributors' => 'Prispievatelia',

    'use_shared_list' => [
        'title' => 'Používať zdieľaný zoznam obchodníkov',
        'help' => 'Beatrax bude čítať priložený zoznam a doplní zrozumiteľné mená obchodníkov, ktorí ešte nemajú vlastné pomenovanie.',
    ],

    'offer_to_contribute' => [
        'title' => 'Ponúkať možnosť prispieť',
        'help' => 'Zobrazí v riadku triedenia výzvu „Pomôž ostatným identifikovať toto“, aby sa dal návrh do zdieľaného zoznamu poslať jedným kliknutím.',
    ],

    'update_on_updates' => [
        'title' => 'Aktualizovať zdieľaný zoznam pri aktualizáciách aplikácie',
        'help' => 'Obnoví priložený zoznam vždy, keď sa Beatrax aktualizuje.',
        'note' => 'Aktivuje sa s budúcou aktualizáciou aplikácie — aktuálnu verziu nájdeš v časti Nastavenia → O aplikácii.',
    ],
];
