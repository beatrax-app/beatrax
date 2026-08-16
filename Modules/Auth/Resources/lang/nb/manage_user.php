<?php

declare(strict_types=1);

return [
    'page_title' => 'Administrer :name · Beatrax',
    'heading' => 'Administrer :name',
    'subtitle' => 'Se, tilbakestill eller generer nye koder for denne brukeren.',

    'set_password' => [
        'heading' => 'Angi nytt passord for denne brukeren',
        'description' => 'Ved neste innlogging blir brukeren bedt om å velge et passord.',
        'open' => 'Angi nytt passord for denne brukeren',
        'body' => 'Angi et nytt passord for :name. Ved neste innlogging blir brukeren bedt om å velge et passord.',
        'label' => 'Nytt passord',
        'submit' => 'Angi passord',
        'cancel' => 'Avbryt',
    ],

    'regenerate' => [
        'heading' => 'Generer nye gjenopprettingskoder for denne brukeren',
        'description' => 'Gamle koder blir ugyldige.',
        'open' => 'Generer nye gjenopprettingskoder for denne brukeren',
        'body' => 'De eksisterende ubrukte kodene til brukeren slutter å virke. Du ser de 10 nye kodene én gang og kan gi dem videre.',
        'confirm_label' => 'Skriv brukernavnet for å fortsette',
        'submit' => 'Generer nye koder',
        'keep' => 'Behold nåværende koder',
        'download' => 'Last ned som .txt',
    ],

    'error_min_length' => 'Bruk minst 12 tegn.',
    'password_set' => 'Passord angitt for :name. Ved neste innlogging blir brukeren bedt om å velge et passord.',
    'codes_regenerated' => 'Ti nye gjenopprettingskoder er generert for :name.',
];
