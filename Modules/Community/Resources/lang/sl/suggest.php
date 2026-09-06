<?php

declare(strict_types=1);

return [
    'heading' => 'Predlagaj preslikavo',
    'intro' => 'Odpre GitHub v tvojem brskalniku z že izpolnjenim predlogom. Z njim gredo samo vzorec, ime, kategorija in regija zgoraj — vzorec pa je opis tako, kot ga je zapisal tvoj izpisek. Tvoje ime in e-pošta nikoli ne zapustita te naprave.',

    'pattern' => 'Vzorec',
    'name' => 'Razumljivo ime',
    'name_placeholder' => 'npr. Albert Heijn',
    'category' => 'Kategorija (neobvezno)',
    'category_placeholder' => 'npr. Živila',
    'region' => 'Regija',

    'regions' => [
        'other' => 'Drugo',
    ],

    'yaml_preview' => 'Predogled YAML',

    'cancel' => 'Prekliči',
    'submit' => 'Odpri na GitHubu',

    'toast' => 'Predlog je odprt v tvojem brskalniku.',

    'errors' => [
        'pattern_required' => 'Vzorec je obvezen.',
        'name_required' => 'Ime je obvezno.',
        'browser_refused' => 'Brskalnika ni bilo mogoče odpreti, zato ni bilo nič poslano in nič ni zapustilo te naprave. Poskusi znova ali zgornji predogled YAML sam prilepi v pull request.',
    ],
];
