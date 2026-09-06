<?php

declare(strict_types=1);

return [
    'heading' => 'Predloži mapiranje',
    'intro' => 'Otvara GitHub u tvom pregledniku s već ispunjenim prijedlogom. S njim idu samo uzorak, naziv, kategorija i regija odozgo — a uzorak je opis onako kako ga je zapisao tvoj izvod. Tvoje ime i e-pošta nikad ne napuštaju ovaj uređaj.',

    'pattern' => 'Uzorak',
    'name' => 'Razumljiv naziv',
    'name_placeholder' => 'npr. Albert Heijn',
    'category' => 'Kategorija (neobavezno)',
    'category_placeholder' => 'npr. Namirnice',
    'region' => 'Regija',

    'regions' => [
        'other' => 'Ostalo',
    ],

    'yaml_preview' => 'Pregled YAML-a',

    'cancel' => 'Odustani',
    'submit' => 'Otvori na GitHubu',

    'toast' => 'Prijedlog je otvoren u tvom pregledniku.',

    'errors' => [
        'pattern_required' => 'Uzorak je obavezan.',
        'name_required' => 'Naziv je obavezan.',
        'browser_refused' => 'Preglednik se nije mogao otvoriti, pa ništa nije poslano i ništa nije napustilo ovaj uređaj. Pokušaj ponovno ili sam zalijepi gornji YAML pregled u pull request.',
    ],
];
