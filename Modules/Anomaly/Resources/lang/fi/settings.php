<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Hälytysherkkyys',
    'sensitivity_help' => 'Kuinka herkästi Beatrax pitää veloitusta epätavallisena tälle kauppiaalle tai luokalle, väliltä 1–100. Suurempi merkitsee useampia.',

    'min_amount_label' => 'Veloituksen vähimmäissumma',
    'min_amount_help' => 'Ohita poikkeamat veloituksissa, jotka jäävät tämän summan alle. Tallennetaan sentteinä (:symbol) — 1000 tarkoittaa :example.',

    'save' => 'Tallenna poikkeama-asetukset',
    'saved' => 'Tallennettu.',

    'suppression' => [
        'summary' => 'Vaimennussäännöt',
        'empty' => 'Ei vielä vaimennussääntöjä. Kun merkitset veloituksen odotetuksi, sääntö ilmestyy tähän.',
        'remove' => 'Poista',
        'remove_aria' => 'Poista vaimennussääntö',
        'removed_toast' => 'Sääntö poistettu',
    ],

    'unknown_merchant' => 'Tuntematon kauppias',

    'detectors' => [
        'large' => 'Suuri veloitus',
        'first_time' => 'Ensimmäinen kerta',
        'duplicate' => 'Kaksoisveloitus',
    ],

    'errors' => [
        'sensitivity_range' => 'Herkkyyden on oltava välillä 1–100.',
        'min_amount_negative' => 'Veloituksen vähimmäissumma ei voi olla negatiivinen.',
    ],
];
