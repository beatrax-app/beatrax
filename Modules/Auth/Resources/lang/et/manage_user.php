<?php

declare(strict_types=1);

return [
    'page_title' => 'Halda kasutajat :name · Beatrax',
    'heading' => 'Halda kasutajat :name',
    'subtitle' => 'Vaata, lähtesta või genereeri sellele kasutajale uued koodid.',

    'set_password' => [
        'heading' => 'Määra sellele kasutajale uus parool',
        'description' => 'Järgmisel sisselogimisel palutakse tal endale parool valida.',
        'open' => 'Määra sellele kasutajale uus parool',
        'body' => 'Määra kasutajale :name uus parool. Järgmisel sisselogimisel palutakse tal endale parool valida.',
        'label' => 'Uus parool',
        'submit' => 'Määra parool',
        'cancel' => 'Tühista',
    ],

    'regenerate' => [
        'heading' => 'Genereeri sellele kasutajale uued taastekoodid',
        'description' => 'Vanad koodid muutuvad kehtetuks.',
        'open' => 'Genereeri sellele kasutajale uued taastekoodid',
        'body' => 'Tema olemasolevad kasutamata koodid lakkavad toimimast. Näed 10 uut koodi ühe korra ja saad need talle edasi anda.',
        'confirm_label' => 'Jätkamiseks sisesta kasutajanimi',
        'submit' => 'Genereeri koodid',
        'keep' => 'Jäta praegused koodid alles',
        'download' => 'Laadi alla .txt-failina',
    ],

    'error_min_length' => 'Kasuta vähemalt 12 märki.',
    'password_set' => 'Kasutajale :name on parool määratud. Järgmisel sisselogimisel palutakse tal endale parool valida.',
    'codes_regenerated' => 'Kasutajale :name genereeriti kümme uut taastekoodi.',
];
