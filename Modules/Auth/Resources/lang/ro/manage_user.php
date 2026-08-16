<?php

declare(strict_types=1);

return [
    'page_title' => 'Gestionează :name · Beatrax',
    'heading' => 'Gestionează :name',
    'subtitle' => 'Vezi, resetează sau regenerează codurile acestui utilizator.',

    'set_password' => [
        'heading' => 'Setează o parolă nouă pentru acest utilizator',
        'description' => 'La următoarea autentificare i se va cere să aleagă o parolă.',
        'open' => 'Setează o parolă nouă pentru acest utilizator',
        'body' => 'Setează o parolă nouă pentru :name. La următoarea autentificare i se va cere să aleagă o parolă.',
        'label' => 'Parolă nouă',
        'submit' => 'Setează parola',
        'cancel' => 'Anulează',
    ],

    'regenerate' => [
        'heading' => 'Regenerează codurile de recuperare pentru acest utilizator',
        'description' => 'Codurile vechi vor fi invalidate.',
        'open' => 'Regenerează codurile de recuperare pentru acest utilizator',
        'body' => 'Codurile lui nefolosite vor înceta să funcționeze. Vei vedea o singură dată cele 10 coduri noi și i le poți preda.',
        'confirm_label' => 'Scrie numele de utilizator ca să continui',
        'submit' => 'Regenerează codurile',
        'keep' => 'Păstrează codurile actuale',
        'download' => 'Descarcă drept .txt',
    ],

    'error_min_length' => 'Folosește cel puțin 12 caractere.',
    'password_set' => 'Parolă setată pentru :name. La următoarea autentificare i se va cere să aleagă o parolă.',
    'codes_regenerated' => 'Zece coduri noi de recuperare generate pentru :name.',
];
