<?php

declare(strict_types=1);

return [
    'page_title' => 'Upravljanje: :name · Beatrax',
    'heading' => 'Upravljanje: :name',
    'subtitle' => 'Pregledaj, resetiraj ili ponovno generiraj kodove ovog korisnika.',

    'set_password' => [
        'heading' => 'Postavi novu lozinku za ovog korisnika',
        'description' => 'Pri sljedećoj prijavi bit će zatraženo da odabere lozinku.',
        'open' => 'Postavi novu lozinku za ovog korisnika',
        'body' => 'Postavi novu lozinku za korisnika :name. Pri sljedećoj prijavi bit će zatraženo da odabere lozinku.',
        'label' => 'Nova lozinka',
        'submit' => 'Postavi lozinku',
        'cancel' => 'Odustani',
    ],

    'regenerate' => [
        'heading' => 'Ponovno generiraj kodove za oporavak za ovog korisnika',
        'description' => 'Stari kodovi prestat će vrijediti.',
        'open' => 'Ponovno generiraj kodove za oporavak za ovog korisnika',
        'body' => 'Postojeći neiskorišteni kodovi prestat će raditi. Novih 10 kodova vidjet ćeš samo jednom i možeš ih predati korisniku.',
        'confirm_label' => 'Upiši korisničko ime za nastavak',
        'submit' => 'Generiraj kodove',
        'keep' => 'Zadrži trenutne kodove',
        'download' => 'Preuzmi kao .txt',
    ],

    'error_min_length' => 'Upotrijebi najmanje 12 znakova.',
    'password_set' => 'Lozinka je postavljena za korisnika :name. Pri sljedećoj prijavi bit će zatraženo da odabere lozinku.',
    'codes_regenerated' => 'Deset novih kodova za oporavak generirano je za korisnika :name.',
];
