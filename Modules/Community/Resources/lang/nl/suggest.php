<?php

declare(strict_types=1);

return [
    'heading' => 'Stel een koppeling voor',
    'intro' => 'Opent GitHub in je browser zodat je de suggestie als concept-PR kunt insturen. Je naam en e-mailadres verlaten dit apparaat nooit.',

    'pattern' => 'Patroon',
    'name' => 'Herkenbare naam',
    'name_placeholder' => 'bijv. Albert Heijn',
    'category' => 'Categorie (optioneel)',
    'category_placeholder' => 'bijv. Boodschappen',
    'region' => 'Regio',

    'regions' => [
        'other' => 'Overige',
    ],

    'yaml_preview' => 'YAML-voorbeeld',

    'cancel' => 'Annuleren',
    'submit' => 'Insturen als concept-PR',

    'toast' => 'Suggestie geopend in je browser.',

    'errors' => [
        'pattern_required' => 'Patroon is verplicht.',
        'name_required' => 'Naam is verplicht.',
        'browser_refused' => 'Je browser kon niet worden geopend, dus er is niets verzonden en er heeft niets dit apparaat verlaten. Probeer het opnieuw, of plak de YAML-voorvertoning hierboven zelf in een pull request.',
    ],
];
