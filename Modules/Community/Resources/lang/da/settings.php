<?php

declare(strict_types=1);

return [
    'about_body' => 'En medfølgende YAML-fil, der kobler kryptiske koder fra kontoudtog til forståelige forhandlernavne. Slår du den til, læser Beatrax listen, når du importerer; når du sender et forslag, åbnes GitHub i din browser.',

    'mappings' => ':count kobling|:count koblinger',
    'contributors' => ':count bidragyder|:count bidragydere',

    'use_shared_list' => [
        'title' => 'Brug den delte forhandlerliste',
        'help' => 'Lad Beatrax læse den medfølgende liste for at udfylde forståelige navne på forhandlere, du ikke selv har omdøbt.',
    ],

    'offer_to_contribute' => [
        'title' => 'Tilbyd at bidrage',
        'help' => 'Vis knappen "Hjælp andre med at genkende denne" på sorteringsrækken, så du kan sende et forslag til den delte liste med ét klik.',
        // i18n-review: da · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Vis knappen "Hjælp andre med at genkende denne" på sorteringsrækken, så du kan sende et forslag til den delte liste med ét tryk.',
    ],

    'update_on_updates' => [
        'title' => 'Opdatér den delte liste ved app-opdateringer',
        'help' => 'Hent den medfølgende liste igen, hver gang Beatrax opdaterer sig selv.',
        'help_phone' => 'Hent den medfølgende liste igen, hver gang en ny version af Beatrax installeres fra App Store eller Google Play.',
        'note' => 'Aktiveres med en fremtidig app-opdatering — se Indstillinger → Om for den aktuelle version.',
    ],
];
