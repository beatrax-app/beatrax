<?php

declare(strict_types=1);

return [
    'heading' => 'Predloži mapiranje',
    'intro' => 'Otvara GitHub u tvom pregledaču za slanje predloga kao skice PR-a. Tvoje ime i e-pošta nikada ne napuštaju ovaj uređaj.',

    'pattern' => 'Šablon',
    'name' => 'Razumljiv naziv',
    'name_placeholder' => 'npr. Albert Heijn',
    'category' => 'Kategorija (opciono)',
    'category_placeholder' => 'npr. Namirnice',
    'region' => 'Region',

    'regions' => [
        'other' => 'Ostalo',
    ],

    'yaml_preview' => 'Pregled YAML-a',

    'cancel' => 'Otkaži',
    'submit' => 'Pošalji kao skicu PR-a',

    'toast' => 'Predlog je otvoren u tvom pregledaču.',

    'errors' => [
        'pattern_required' => 'Šablon je obavezan.',
        'name_required' => 'Naziv je obavezan.',
        'browser_refused' => 'Pregledač nije mogao da se otvori, pa ništa nije poslato i ništa nije napustilo ovaj uređaj. Pokušaj ponovo ili sam nalepi gornji YAML pregled u pull request.',
    ],
];
