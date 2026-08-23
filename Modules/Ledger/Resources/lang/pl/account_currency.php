<?php

declare(strict_types=1);

return [
    'heading' => 'Waluta konta',
    'intro' => 'Waluta, w której denominowane jest każde konto. Nowe konto zaczyna w walucie bazowej.',
    'no_accounts' => 'Nie ma jeszcze kont.',
    'legend' => 'Waluta konta :name',
    'label' => 'Waluta',
    'help' => 'Waluta, w której to konto podaje swoje saldo.',
    'save' => 'Zapisz walutę',
    'saved' => 'Zapisano',

    'toast' => [
        'updated' => ':name podaje teraz kwoty w :currency.',
    ],

    'errors' => [
        'unknown' => 'Ta instalacja nie zna takiej waluty.',
    ],

    'warning' => [
        'intro' => 'Zmiana konta z :from na :to zmienia tylko etykietę. Nic z zapisanych danych nie jest przeliczane ani nadpisywane.',
        'baseline' => 'Saldo początkowe :amount pozostaje dokładnie tą kwotą i od teraz jest odczytywane jako :to.',
        'lines' => 'To konto zawiera obecnie:',
        'reads' => 'Po zmianie konto podaje swoją linię :to — zero, jeśli nie ma nic w :to.',
        'confirm' => 'Zmień mimo to',
        'keep' => 'Zachowaj :currency',
    ],
];
