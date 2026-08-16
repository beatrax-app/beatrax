<?php

declare(strict_types=1);

return [
    'what_heading' => 'Mistä haluat ilmoituksen',

    'reminders' => [
        'label' => 'Maksumuistutukset',
        'help' => 'Saat muistutuksen ennen toistuvan maksun eräpäivää.',
    ],

    'lead_days' => [
        'label' => 'Muistuta ___ päivää ennen',
        'help' => 'Kuinka monta päivää ennen eräpäivää muistutus lähtee. 1–30 päivää.',
    ],

    'budget_nudges' => [
        'label' => 'Budjettivinkit',
        'help' => 'Saat tiedon, kun kategorian budjetti on lähes käytetty.',
    ],

    'digest' => [
        'label' => 'Viikoittainen tilanteesi',
        'help' => 'Kuinka usein saat yhteenvedon tämän jakson tilanteesta.',
        'daily' => 'Päivittäin',
        'weekly' => 'Viikoittain',
        'off' => 'Ei käytössä',
    ],

    'savings' => [
        'label' => 'Säästövinkit',
        'help' => 'Saat tiedon, kun Beatrax löytää halvemman sopimuksen tai muun säästökohteen.',
    ],

    'when_heading' => 'Milloin ja miten',

    'quiet_hours' => [
        'label' => 'Hiljaiset tunnit',
        'help' => 'Ei ääntä eikä banneria tänä aikana — ilmoitukset päätyvät silti ilmoituslistallesi.',
        'from' => 'Alkaen',
        'to' => 'Asti',
    ],

    'hide_details' => [
        'label' => 'Piilota tiedot ilmoituksista',
        'help' => 'Näytä summat ja kauppiaiden nimet itse ilmoitusbannerissa. Ota pois päältä, jos näyttösi voi näkyä muille.',
    ],

    'save' => 'Tallenna ilmoitusasetukset',
    'saved' => 'Tallennettu.',

    'other_devices' => [
        'summary' => 'Muut laitteet',
        'empty' => 'Muita laitteita ei ole vielä paritettu.',
        'unnamed' => 'Nimetön laite',

        'summary_line' => 'muistutukset :reminders · vinkit :nudges · yhteenveto :digest · säästöt :savings',
        'on' => 'päällä',
        'off' => 'pois',
    ],

    'errors' => [
        'save_failed' => 'Ilmoitusasetuksiasi ei voitu tallentaa. Yritä uudelleen.',
    ],
];
