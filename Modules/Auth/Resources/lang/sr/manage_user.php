<?php

declare(strict_types=1);

return [
    'page_title' => 'Upravljanje: :name · Beatrax',
    'heading' => 'Upravljanje: :name',
    'subtitle' => 'Pregledaj, resetuj ili ponovo generiši kodove ovog korisnika.',

    'set_password' => [
        'heading' => 'Postavi novu lozinku za ovog korisnika',
        'description' => 'Pri sledećoj prijavi biće zatraženo da izabere lozinku.',
        'open' => 'Postavi novu lozinku za ovog korisnika',
        'body' => 'Postavi novu lozinku za korisnika :name. Pri sledećoj prijavi biće zatraženo da izabere lozinku.',
        'label' => 'Nova lozinka',
        'submit' => 'Postavi lozinku',
        'cancel' => 'Otkaži',
    ],

    'regenerate' => [
        'heading' => 'Ponovo generiši kodove za oporavak za ovog korisnika',
        'description' => 'Stari kodovi prestaće da važe.',
        'open' => 'Ponovo generiši kodove za oporavak za ovog korisnika',
        'body' => 'Postojeći neiskorišćeni kodovi prestaće da rade. Novih 10 kodova videćeš samo jednom i možeš ih predati korisniku.',
        'confirm_label' => 'Upiši korisničko ime za nastavak',
        'submit' => 'Generiši kodove',
        'keep' => 'Zadrži trenutne kodove',
        'download' => 'Preuzmi kao .txt',
    ],

    'error_min_length' => 'Upotrebi najmanje 12 znakova.',
    'password_set' => 'Lozinka je postavljena za korisnika :name. Pri sledećoj prijavi biće zatraženo da izabere lozinku.',
    'codes_regenerated' => 'Deset novih kodova za oporavak generisano je za korisnika :name.',
];
