<?php

declare(strict_types=1);

return [
    'about_body' => 'En medföljande YAML-fil som kopplar kryptiska koder från kontoutdrag till begripliga handlarnamn. Slår du på den läser Beatrax listan när du importerar; när du skickar in ett förslag öppnas GitHub i din webbläsare.',

    'mappings' => ':count koppling|:count kopplingar',
    'contributors' => ':count bidragsgivare|:count bidragsgivare',

    'use_shared_list' => [
        'title' => 'Använd den delade handlarlistan',
        'help' => 'Låt Beatrax läsa den medföljande listan för att fylla i begripliga namn på handlare som du inte själv har döpt om.',
    ],

    'offer_to_contribute' => [
        'title' => 'Erbjud att bidra',
        'help' => 'Visa knappen "Hjälp andra att känna igen den här" på sorteringsraden så att du kan skicka in ett förslag till den delade listan med ett klick.',
        // i18n-review: sv · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Visa knappen "Hjälp andra att känna igen den här" på sorteringsraden så att du kan skicka in ett förslag till den delade listan med ett tryck.',
    ],

    'update_on_updates' => [
        'title' => 'Uppdatera den delade listan vid appuppdateringar',
        'help' => 'Hämta den medföljande listan på nytt varje gång Beatrax uppdaterar sig själv.',
        'help_phone' => 'Hämta den medföljande listan på nytt varje gång en ny version av Beatrax installeras från App Store eller Google Play.',
        'note' => 'Aktiveras med en framtida appuppdatering — versionen du kör visas högst upp i sidopanelen.',
    ],
];
