<?php

declare(strict_types=1);

return [
    'page_title' => 'Tuo YNAB- tai Actual-sovelluksesta',

    'eyebrow' => 'Tietojen siirrot',
    'heading' => 'Tuo YNAB- tai Actual-sovelluksesta',
    'intro' => 'Tuo kategoriapuusi, budjettihistoriasi ja tapahtumasi YNAB4-ohjelmasta, uudesta YNABista tai Actual Budgetista. Mitään ei kirjoiteta tilikirjaasi ennen kuin tarkistat ja vahvistat.',
    'reconcile_context' => 'Tarkistetaan päivitykset viimeisintä :product-tuontiasi vasten.',

    'source_label' => 'Lähde',
    'file_label' => 'Tiedosto',
    'parse_button' => 'Jäsennä vienti',

    'hints' => [
        'ynab4' => 'Vie koko budjettisi ZIP-tiedostona YNAB4-ohjelman valikosta File → Export.',
        'nynab' => 'Vie budjettisi nYNABista valikosta File → Export Budget ja pakkaa viedyt CSV-tiedostot ZIP-tiedostoksi.',
        'actual' => 'Vie budjettisi ZIP-tiedostona Actual Budgetin kohdasta Settings → Export data.',
    ],

    'errors' => [
        'unrecognised' => 'Tämä ei näytä YNAB4-, nYNAB- tai Actual-vienniltä, jonka osaamme lukea. Tarkista tiedosto ja yritä uudelleen.',
        'file_too_large' => 'Tiedosto on liian suuri siirtovienniksi.',
    ],
];
