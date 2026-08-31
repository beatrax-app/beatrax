<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Waarschuwingsgevoeligheid',
    'sensitivity_help' => 'Hoe snel Beatrax een afschrijving ongebruikelijk noemt voor die winkelier of categorie, van 1 tot 100. Hoger markeert meer.',

    'min_amount_label' => 'Minimaal afschrijvingsbedrag',
    'min_amount_help' => 'Negeer anomalieën op afschrijvingen onder dit bedrag. Opgeslagen in kleinste eenheden (:symbol) — :minor betekent :example.',

    'save' => 'Anomalie-instellingen opslaan',
    'saved' => 'Opgeslagen.',

    'suppression' => [
        'summary' => 'Onderdrukkingsregels',
        'empty' => 'Nog geen onderdrukkingsregels. Zodra je een afschrijving als verwacht markeert, verschijnt hier een regel.',
        'remove' => 'Verwijderen',
        'remove_aria' => 'Onderdrukkingsregel verwijderen',
        'removed_toast' => 'Regel verwijderd',
    ],

    'unknown_merchant' => 'Onbekende winkelier',

    'detectors' => [
        'large' => 'Grote afschrijving',
        'first_time' => 'Eerste keer',
        'duplicate' => 'Duplicaat',
    ],

    'errors' => [
        'sensitivity_range' => 'Gevoeligheid moet tussen 1 en 100 liggen.',
        'min_amount_negative' => 'Minimaal afschrijvingsbedrag kan niet negatief zijn.',
    ],
];
