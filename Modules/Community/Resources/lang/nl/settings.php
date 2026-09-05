<?php

declare(strict_types=1);

return [
    'about_body' => 'Een meegeleverd YAML-bestand dat cryptische bankafschriftcodes koppelt aan herkenbare winkeliersnamen. Inschakelen laat Beatrax de lijst lezen bij het importeren; een suggestie insturen opent GitHub in je browser.',

    'mappings' => ':count koppeling|:count koppelingen',
    'contributors' => ':count bijdrager|:count bijdragers',

    'use_shared_list' => [
        'title' => 'Gebruik de gedeelde winkelierslijst',
        'help' => 'Laat Beatrax de meegeleverde lijst lezen om herkenbare namen in te vullen voor winkeliers die je nog niet zelf hebt hernoemd.',
    ],

    'offer_to_contribute' => [
        'title' => 'Bijdragen aanbieden',
        'help' => 'Toon de knop "Help anderen dit herkennen" op de triageregel zodat je met één klik een suggestie aan de gedeelde lijst kunt insturen.',
        'help_touch' => 'Toon de knop "Help anderen dit herkennen" op de triageregel zodat je met één tik een suggestie aan de gedeelde lijst kunt insturen.',
    ],

    'update_on_updates' => [
        'title' => 'Gedeelde lijst bijwerken bij app-updates',
        'help' => 'Ververs de meegeleverde lijst telkens wanneer Beatrax zichzelf bijwerkt.',
        'help_phone' => 'Ververs de meegeleverde lijst telkens wanneer er een nieuwe versie van Beatrax uit de App Store of Google Play wordt geïnstalleerd.',
        'note' => 'Wordt geactiveerd bij een toekomstige app-update — de versie die je gebruikt staat bovenaan de zijbalk.',
    ],
];
