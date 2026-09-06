<?php

declare(strict_types=1);

return [
    'heading' => 'Föreslå en koppling',
    'intro' => 'Öppnar GitHub i din webbläsare med förslaget ifyllt. Bara mönstret, namnet, kategorin och regionen ovan följer med — och mönstret är beskrivningen precis som ditt kontoutdrag skrev den. Ditt namn och din e-postadress lämnar aldrig den här enheten.',

    'pattern' => 'Mönster',
    'name' => 'Begripligt namn',
    'name_placeholder' => 't.ex. Albert Heijn',
    'category' => 'Kategori (valfritt)',
    'category_placeholder' => 't.ex. Livsmedel',
    'region' => 'Region',

    'regions' => [
        'other' => 'Övrigt',
    ],

    'yaml_preview' => 'YAML-förhandsgranskning',

    'cancel' => 'Avbryt',
    'submit' => 'Öppna på GitHub',

    'toast' => 'Förslaget öppnades i din webbläsare.',

    'errors' => [
        'pattern_required' => 'Mönster är obligatoriskt.',
        'name_required' => 'Namn är obligatoriskt.',
        'browser_refused' => 'Din webbläsare gick inte att öppna, så inget skickades och inget lämnade den här enheten. Försök igen, eller kopiera YAML-förhandsvisningen ovan till en pull request själv.',
    ],
];
