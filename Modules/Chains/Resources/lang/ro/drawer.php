<?php

declare(strict_types=1);

return [
    'heading_named' => 'Lanț pentru :name',
    'heading' => 'Lanț',

    'unresolved_heading' => 'Lanțul nu este încă rezolvat',
    'unresolved_body' => 'Rezolvatorul de lanțuri încă rulează. Deschide coada de verificare sau reîmprospătează peste câteva momente.',

    'none_heading' => 'Niciun lanț de finanțare găsit',
    'none_body' => 'Această tranzacție nu are un lanț de finanțare detectat. Dacă te așteptai la unul, trimite un candidat din coada de verificare.',

    'none_beyond_leg' => 'Niciun lanț de finanțare dincolo de acest segment.',

    'covers_charges' => 'Acoperă :count plată ICS|Acoperă :count plăți ICS|Acoperă :count de plăți ICS',
    'show_more_fanout' => 'Arată încă :count · :shown din :total',

    'confirm' => 'Confirmă',
    'reject' => 'Respinge',
    'confirm_aria' => 'Confirmă legătura de lanț :id',
    'reject_aria' => 'Respinge legătura de lanț :id',

    'confidence_tier' => [
        'deterministic' => 'Deterministă',
        'confirmed' => 'Confirmată',
        'candidate' => 'Candidat',
    ],

    'confidence_aria' => [
        'deterministic' => 'Încredere: potrivire deterministă',
        'confirmed' => 'Încredere: confirmată',
        'candidate' => 'Încredere: candidat; necesită verificare',
    ],
];
