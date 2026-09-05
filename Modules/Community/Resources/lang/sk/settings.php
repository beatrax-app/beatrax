<?php

declare(strict_types=1);

return [
    'about_body' => 'Priložený YAML súbor, ktorý mapuje kryptické kódy z bankových výpisov na zrozumiteľné mená obchodníkov. Po zapnutí môže Beatrax tento zoznam čítať pri importe; odoslanie návrhu otvorí GitHub v prehliadači.',

    'mappings' => ':count mapovanie|:count mapovania|:count mapovaní',
    'contributors' => ':count prispievateľ|:count prispievatelia|:count prispievateľov',

    'use_shared_list' => [
        'title' => 'Používať zdieľaný zoznam obchodníkov',
        'help' => 'Beatrax bude čítať priložený zoznam a doplní zrozumiteľné mená obchodníkov, ktorí ešte nemajú vlastné pomenovanie.',
    ],

    'offer_to_contribute' => [
        'title' => 'Ponúkať možnosť prispieť',
        'help' => 'Zobrazí v riadku triedenia výzvu „Pomôž ostatným identifikovať toto“, aby sa dal návrh do zdieľaného zoznamu poslať jedným kliknutím.',
        // i18n-review: sk · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Zobrazí v riadku triedenia výzvu „Pomôž ostatným identifikovať toto“, aby sa dal návrh do zdieľaného zoznamu poslať jedným ťuknutím.',
    ],

    'update_on_updates' => [
        'title' => 'Aktualizovať zdieľaný zoznam pri aktualizáciách aplikácie',
        'help' => 'Obnoví priložený zoznam vždy, keď sa Beatrax aktualizuje.',
        'help_phone' => 'Obnoví priložený zoznam vždy, keď sa z App Store alebo Google Play nainštaluje nová verzia Beatraxu.',
        'note' => 'Aktivuje sa s budúcou aktualizáciou aplikácie — verziu, ktorú používaš, nájdeš hore v bočnom paneli.',
    ],
];
