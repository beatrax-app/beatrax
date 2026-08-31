<?php

declare(strict_types=1);

return [
    'page_title' => 'Kassakirja',
    'heading' => 'Kassakirja',
    'intro' => 'Kirjaa käteismenot ja muut pankin ulkopuoliset menot käsin. Manuaaliset kirjaukset päätyvät samaan tilikirjaan kuin tuonnit — ne luokitellaan, yhdistetään vastapuoleen, tunnistetaan toistuviksi ja lasketaan mukaan kuukauteesi.',

    'direction' => 'Suunta',
    'expense' => 'Meno',
    'income' => 'Tulo',

    'amount' => 'Summa (:symbol)',
    'date' => 'Päivämäärä',
    'counterparty' => 'Vastapuoli',
    'counterparty_placeholder' => 'esim. Leipomo',
    'category' => 'Kategoria',
    'optional' => '(valinnainen)',
    'uncategorized' => 'Luokittelematon',
    'note' => 'Muistiinpano',

    'add_entry' => 'Lisää kirjaus',
    'manual_entries' => 'Manuaaliset kirjaukset',
    'no_entries' => 'Ei vielä manuaalisia kirjauksia.',
    'delete_entry' => 'Poista kirjaus',
    'delete_entry_caption' => 'Poista',
    'delete' => 'Poista',
    'delete_confirm' => 'Poistetaanko tämä kirjaus?',
    'delete_keep' => 'Säilytä',

    'errors' => [
        'amount_positive' => 'Anna nollaa suurempi summa.',
        'amount_too_large' => 'Summa on liian suuri. Tarkista numerot.',
        'amount_unreadable' => 'Summaa ei voitu lukea. Anna se enintään :decimals desimaalilla, esimerkiksi :example.|Summaa ei voitu lukea. Anna se enintään :decimals desimaalilla, esimerkiksi :example.',
        'amount_unreadable_whole' => 'Summaa ei voitu lukea. Tällä valuutalla ei ole desimaaleja, joten anna kokonaisluku, esimerkiksi :example.',
        'invalid_date' => 'Anna kelvollinen päivämäärä.',
        'not_recorded' => 'Merkintää ei tallennettu. Yritä lisätä se uudelleen.',
    ],

    'toast' => [
        'added' => 'Käteiskirjaus lisätty.',
        'removed' => 'Käteiskirjaus poistettu.',
        'reconciled_locked' => 'Tämä tapahtuma on täsmäytetty. Pura täsmäytys, niin voit tehdä muutoksia.',
    ],
];
