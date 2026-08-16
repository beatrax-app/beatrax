<?php

declare(strict_types=1);

return [
    'page_title' => 'Administrér :name · Beatrax',
    'heading' => 'Administrér :name',
    'subtitle' => 'Se, nulstil eller generér nye koder for denne bruger.',

    'set_password' => [
        'heading' => 'Angiv ny adgangskode for denne bruger',
        'description' => 'Ved næste login bliver brugeren bedt om at vælge en adgangskode.',
        'open' => 'Angiv ny adgangskode for denne bruger',
        'body' => 'Angiv en ny adgangskode for :name. Ved næste login bliver brugeren bedt om at vælge en adgangskode.',
        'label' => 'Ny adgangskode',
        'submit' => 'Angiv adgangskode',
        'cancel' => 'Annullér',
    ],

    'regenerate' => [
        'heading' => 'Generér nye gendannelseskoder for denne bruger',
        'description' => 'Gamle koder bliver ugyldige.',
        'open' => 'Generér nye gendannelseskoder for denne bruger',
        'body' => 'Brugerens eksisterende ubrugte koder holder op med at virke. Du får de 10 nye koder vist én gang og kan give dem videre.',
        'confirm_label' => 'Skriv brugernavnet for at fortsætte',
        'submit' => 'Generér nye koder',
        'keep' => 'Behold nuværende koder',
        'download' => 'Hent som .txt',
    ],

    'error_min_length' => 'Brug mindst 12 tegn.',
    'password_set' => 'Adgangskode angivet for :name. Ved næste login bliver brugeren bedt om at vælge en adgangskode.',
    'codes_regenerated' => 'Ti nye gendannelseskoder er genereret til :name.',
];
