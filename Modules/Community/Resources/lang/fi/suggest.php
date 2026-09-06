<?php

declare(strict_types=1);

return [
    'heading' => 'Ehdota vastaavuutta',
    'intro' => 'Avaa GitHubin selaimeesi ehdotus valmiiksi täytettynä. Mukaan lähtevät vain yllä oleva kuvio, nimi, kategoria ja alue — ja kuvio on kuvaus sellaisena kuin tiliotteesi sen kirjoitti. Nimesi ja sähköpostiosoitteesi eivät koskaan poistu tältä laitteelta.',

    'pattern' => 'Kuvio',
    'name' => 'Selkeä nimi',
    'name_placeholder' => 'esim. Albert Heijn',
    'category' => 'Kategoria (valinnainen)',
    'category_placeholder' => 'esim. Ruokaostokset',
    'region' => 'Alue',

    'regions' => [
        'other' => 'Muu',
    ],

    'yaml_preview' => 'YAML-esikatselu',

    'cancel' => 'Peruuta',
    'submit' => 'Avaa GitHubissa',

    'toast' => 'Ehdotus avattu selaimeesi.',

    'errors' => [
        'pattern_required' => 'Kuvio on pakollinen.',
        'name_required' => 'Nimi on pakollinen.',
        'browser_refused' => 'Selaintasi ei voitu avata, joten mitään ei lähetetty eikä mikään poistunut tältä laitteelta. Yritä uudelleen tai kopioi yllä oleva YAML-esikatselu itse pull requestiin.',
    ],
];
