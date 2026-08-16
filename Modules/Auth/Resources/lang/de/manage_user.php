<?php

declare(strict_types=1);

return [
    'page_title' => ':name verwalten · Beatrax',
    'heading' => ':name verwalten',
    'subtitle' => 'Codes für diesen Benutzer ansehen, zurücksetzen oder neu erzeugen.',

    'set_password' => [
        'heading' => 'Neues Passwort für diesen Benutzer festlegen',
        'description' => 'Bei der nächsten Anmeldung muss ein eigenes Passwort gewählt werden.',
        'open' => 'Neues Passwort für diesen Benutzer festlegen',
        'body' => 'Lege ein neues Passwort für :name fest. Bei der nächsten Anmeldung muss ein eigenes Passwort gewählt werden.',
        'label' => 'Neues Passwort',
        'submit' => 'Passwort festlegen',
        'cancel' => 'Abbrechen',
    ],

    'regenerate' => [
        'heading' => 'Wiederherstellungscodes für diesen Benutzer neu erzeugen',
        'description' => 'Alte Codes werden ungültig.',
        'open' => 'Wiederherstellungscodes für diesen Benutzer neu erzeugen',
        'body' => 'Die vorhandenen, noch nicht genutzten Codes funktionieren dann nicht mehr. Du siehst die 10 neuen Codes einmalig und kannst sie weitergeben.',
        'confirm_label' => 'Gib den Benutzernamen ein, um fortzufahren',
        'submit' => 'Codes neu erzeugen',
        'keep' => 'Aktuelle Codes behalten',
        'download' => 'Als .txt herunterladen',
    ],

    'error_min_length' => 'Verwende mindestens 12 Zeichen.',
    'password_set' => 'Passwort für :name festgelegt. Bei der nächsten Anmeldung muss ein eigenes Passwort gewählt werden.',
    'codes_regenerated' => 'Zehn neue Wiederherstellungscodes für :name erzeugt.',
];
