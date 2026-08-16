<?php

declare(strict_types=1);

return [
    'page_title' => 'Upravljanje: :name · Beatrax',
    'heading' => 'Upravljanje: :name',
    'subtitle' => 'Preglej, ponastavi ali znova ustvari kode tega uporabnika.',

    'set_password' => [
        'heading' => 'Nastavi novo geslo za tega uporabnika',
        'description' => 'Ob naslednji prijavi bo pozvan, da izbere geslo.',
        'open' => 'Nastavi novo geslo za tega uporabnika',
        'body' => 'Nastavi novo geslo za uporabnika :name. Ob naslednji prijavi bo pozvan, da izbere geslo.',
        'label' => 'Novo geslo',
        'submit' => 'Nastavi geslo',
        'cancel' => 'Prekliči',
    ],

    'regenerate' => [
        'heading' => 'Znova ustvari kode za obnovitev za tega uporabnika',
        'description' => 'Stare kode ne bodo več veljavne.',
        'open' => 'Znova ustvari kode za obnovitev za tega uporabnika',
        'body' => 'Obstoječe neuporabljene kode ne bodo več delovale. Novih 10 kod boš videl samo enkrat in jih lahko predaš naprej.',
        'confirm_label' => 'Vpiši uporabniško ime za nadaljevanje',
        'submit' => 'Ustvari kode',
        'keep' => 'Obdrži trenutne kode',
        'download' => 'Prenesi kot .txt',
    ],

    'error_min_length' => 'Uporabi vsaj 12 znakov.',
    'password_set' => 'Geslo za uporabnika :name je nastavljeno. Ob naslednji prijavi bo pozvan, da izbere geslo.',
    'codes_regenerated' => 'Za uporabnika :name je ustvarjenih deset novih kod za obnovitev.',
];
