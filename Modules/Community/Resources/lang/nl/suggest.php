<?php

declare(strict_types=1);

return [
    'heading' => 'Stel een koppeling voor',
    'intro' => 'Opent GitHub in je browser met de suggestie al ingevuld. Alleen het patroon, de naam, de categorie en de regio hierboven gaan mee — en het patroon is de omschrijving zoals je afschrift die schreef. Je naam en e-mailadres verlaten dit apparaat nooit.',

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
    'submit' => 'Openen op GitHub',

    'toast' => 'Suggestie geopend in je browser.',

    'errors' => [
        'pattern_required' => 'Patroon is verplicht.',
        'name_required' => 'Naam is verplicht.',
        'browser_refused' => 'Je browser kon niet worden geopend, dus er is niets verzonden en er heeft niets dit apparaat verlaten. Probeer het opnieuw, of plak de YAML-voorvertoning hierboven zelf in een pull request.',
    ],
];
