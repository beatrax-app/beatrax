<?php

declare(strict_types=1);

return [
    'page_title' => 'Hantera :name · Beatrax',
    'heading' => 'Hantera :name',
    'subtitle' => 'Visa, återställ eller generera om koder för den här användaren.',

    'set_password' => [
        'heading' => 'Ange nytt lösenord för den här användaren',
        'description' => 'Vid nästa inloggning ombeds användaren att välja ett lösenord.',
        'open' => 'Ange nytt lösenord för den här användaren',
        'body' => 'Ange ett nytt lösenord för :name. Vid nästa inloggning ombeds användaren att välja ett lösenord.',
        'label' => 'Nytt lösenord',
        'submit' => 'Ange lösenord',
        'cancel' => 'Avbryt',
    ],

    'regenerate' => [
        'heading' => 'Generera nya återställningskoder för den här användaren',
        'description' => 'Gamla koder blir ogiltiga.',
        'open' => 'Generera nya återställningskoder för den här användaren',
        'body' => 'Användarens befintliga oanvända koder slutar fungera. Du ser de 10 nya koderna en gång och kan lämna över dem.',
        'confirm_label' => 'Skriv användarnamnet för att fortsätta',
        'submit' => 'Generera nya koder',
        'keep' => 'Behåll nuvarande koder',
        'download' => 'Ladda ner som .txt',
    ],

    'error_min_length' => 'Använd minst 12 tecken.',
    'password_set' => 'Lösenord angivet för :name. Vid nästa inloggning ombeds användaren att välja ett lösenord.',
    'codes_regenerated' => 'Tio nya återställningskoder har genererats för :name.',
];
