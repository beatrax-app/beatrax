<?php

declare(strict_types=1);

return [
    'page_title' => 'Kasos knyga',
    'heading' => 'Kasos knyga',
    'intro' => 'Grynųjų ir kitas ne banko išlaidas įrašyk ranka. Rankiniai įrašai patenka į tą pačią didžiąją knygą kaip ir importai — jiems priskiriama kategorija, jie tikrinami dėl pasikartojimo ir įskaičiuojami į mėnesio suvestinę.',

    'direction' => 'Kryptis',
    'expense' => 'Išlaidos',
    'income' => 'Pajamos',

    'amount' => 'Suma (:symbol)',
    'date' => 'Data',
    'counterparty' => 'Kita šalis',
    'counterparty_placeholder' => 'pvz. Kepykla',
    'category' => 'Kategorija',
    'optional' => '(neprivaloma)',
    'uncategorized' => 'Be kategorijos',
    'note' => 'Pastaba',

    'add_entry' => 'Pridėti įrašą',
    'manual_entries' => 'Rankiniai įrašai',
    'no_entries' => 'Kol kas rankinių įrašų nėra.',
    'delete_entry' => 'Ištrinti įrašą',
    'delete' => 'Ištrinti',
    'delete_confirm' => 'Ištrinti šį įrašą?',
    'delete_keep' => 'Palikti',

    'errors' => [
        'amount_positive' => 'Įvesk už nulį didesnę sumą.',
        'amount_too_large' => 'Ši suma per didelė. Patikrink skaitmenis.',
        'amount_unreadable' => 'Nepavyko nuskaityti šios sumos. Įveskite ją be tūkstančių skirtuko ir ne daugiau kaip dviem skaitmenimis po kablelio, pavyzdžiui, :example.',
        'invalid_date' => 'Įvesk tinkamą datą.',
    ],

    'toast' => [
        'added' => 'Grynųjų įrašas pridėtas.',
        'removed' => 'Grynųjų įrašas pašalintas.',
    ],
];
