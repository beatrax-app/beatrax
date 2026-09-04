<?php

declare(strict_types=1);

return [
    'page_title' => 'Import bekijken',

    'heading' => 'Import bekijken',
    'subtitle' => 'Bekijk wat er verandert. Er wordt niets opgeslagen totdat je bevestigt.',

    'stats' => [
        'category' => 'Categorieën',
        'account' => 'Rekeningen',
        'payee' => 'Tegenpartijen',
        'transaction' => 'Transacties',
        'budget' => 'Budgetmaanden',
    ],

    'all_clean' => 'Alles is netjes gekoppeld — er is hier niets waarover je hoeft te beslissen.',

    'nothing_staged' => 'Deze export bevatte niets om te importeren — er valt hier niets te bevestigen.',

    'discarded' => 'Je hebt deze import verworpen, dus er valt hier niets meer te bekijken.',
    'discarded_link' => 'Begin een nieuwe import',

    'groups' => [
        'conflict' => 'Vereist jouw beslissing',
        'extra' => 'Niet geïmporteerd',
    ],

    'keep_or_take_aria' => 'Lokaal behouden of bron overnemen voor :label',
    'keep_local' => 'Lokaal behouden',
    'take_source' => 'Bron overnemen',

    'footer_note' => 'Hiermee worden de hierboven getoonde aantallen aangemaakt of bijgewerkt in je categorieën, budgetten en grootboek.',
    'discard_button' => 'Import verwerpen',
    'discard_confirm' => 'Deze import verwerpen? Alles wat uit je exportbestand is gelezen wordt hier verwijderd, en terughalen betekent het hele bestand opnieuw uploaden en inlezen. Er is nog niets in je grootboek terechtgekomen.',
    'confirm_button' => 'Import bevestigen',
];
