<?php

declare(strict_types=1);

return [
    'about_body' => 'Mukana toimitettava YAML-tiedosto, joka yhdistää kryptiset tiliotekoodit selkeisiin kauppiasnimiin. Kun asetus on päällä, Beatrax lukee listaa tuonnin yhteydessä; ehdotuksen lähettäminen avaa GitHubin selaimeesi.',

    'mappings' => ':count vastaavuus|:count vastaavuutta',
    'contributors' => ':count osallistuja|:count osallistujaa',

    'use_shared_list' => [
        'title' => 'Käytä jaettua kauppiaslistaa',
        'help' => 'Anna Beatraxin lukea mukana toimitettavaa listaa ja täyttää selkeät nimet kauppiaille, joita et ole itse nimennyt uudelleen.',
    ],

    'offer_to_contribute' => [
        'title' => 'Tarjoa osallistumista',
        'help' => 'Näytä käsittelyrivillä ”Auta muita tunnistamaan tämä” -kehote, jotta voit lähettää ehdotuksen jaettuun listaan yhdellä napsautuksella.',
        // i18n-review: fi · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Näytä käsittelyrivillä ”Auta muita tunnistamaan tämä” -kehote, jotta voit lähettää ehdotuksen jaettuun listaan yhdellä napautuksella.',
    ],

    'update_on_updates' => [
        'title' => 'Päivitä jaettu lista sovelluspäivitysten yhteydessä',
        'help' => 'Päivitä mukana toimitettava lista aina kun Beatrax päivittää itsensä.',
        'help_phone' => 'Päivitä mukana toimitettava lista aina kun App Storesta tai Google Playstä asennetaan uusi Beatraxin versio.',
        'note' => 'Aktivoituu tulevassa sovelluspäivityksessä — katso nykyinen versio kohdasta Asetukset → Tietoja.',
    ],
];
