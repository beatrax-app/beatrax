<?php

declare(strict_types=1);

return [
    'page_title' => 'Hallitse käyttäjää :name · Beatrax',
    'heading' => 'Hallitse käyttäjää :name',
    'subtitle' => 'Katso, nollaa tai luo uudet koodit tälle käyttäjälle.',

    'set_password' => [
        'heading' => 'Aseta tälle käyttäjälle uusi salasana',
        'description' => 'Seuraavalla kirjautumiskerralla häntä pyydetään valitsemaan salasana.',
        'open' => 'Aseta tälle käyttäjälle uusi salasana',
        'body' => 'Aseta käyttäjälle :name uusi salasana. Seuraavalla kirjautumiskerralla häntä pyydetään valitsemaan salasana.',
        'label' => 'Uusi salasana',
        'submit' => 'Aseta salasana',
        'cancel' => 'Peruuta',
    ],

    'regenerate' => [
        'heading' => 'Luo tälle käyttäjälle uudet palautuskoodit',
        'description' => 'Vanhat koodit mitätöityvät.',
        'open' => 'Luo tälle käyttäjälle uudet palautuskoodit',
        'body' => 'Hänen nykyiset käyttämättömät koodinsa lakkaavat toimimasta. Näet 10 uutta koodia kerran ja voit luovuttaa ne hänelle.',
        'confirm_label' => 'Kirjoita käyttäjätunnus jatkaaksesi',
        'submit' => 'Luo uudet koodit',
        'keep' => 'Säilytä nykyiset koodit',
        'download' => 'Lataa .txt-tiedostona',
    ],

    'error_min_length' => 'Käytä vähintään 12 merkkiä.',
    'password_set' => 'Salasana asetettu käyttäjälle :name. Seuraavalla kirjautumiskerralla häntä pyydetään valitsemaan salasana.',
    'codes_regenerated' => 'Kymmenen uutta palautuskoodia luotu käyttäjälle :name.',
];
