<?php

declare(strict_types=1);

return [
    'heading' => 'Tilin valuutta',
    'intro' => 'Valuutta, jonka määräinen kukin tili on. Uusi tili alkaa perusvaluutassa.',
    'no_accounts' => 'Ei vielä tilejä.',
    'legend' => 'Tilin :name valuutta',
    'label' => 'Valuutta',
    'help' => 'Valuutta, jossa tämä tili ilmoittaa saldonsa.',
    'save' => 'Tallenna valuutta',
    'saved' => 'Tallennettu',

    'toast' => [
        'updated' => ':name ilmoittaa nyt valuutassa :currency.',
    ],

    'errors' => [
        'unknown' => 'Tämä asennus ei tunne tuota valuuttaa.',
    ],

    'warning' => [
        'intro' => 'Tilin vaihtaminen valuutasta :from valuuttaan :to vain vaihtaa merkinnän. Mitään tallennettua ei muunneta eikä kirjoiteta uudelleen.',
        'baseline' => 'Alkusaldo :amount pysyy täsmälleen samana lukuna ja luetaan tästä lähtien valuuttana :to.',
        'lines' => 'Tilillä on tällä hetkellä:',
        'reads' => 'Muutoksen jälkeen tili ilmoittaa :to-rivinsä — nolla, jos sillä ei ole mitään valuutassa :to.',
        'confirm' => 'Vaihda silti',
        'keep' => 'Säilytä :currency',
    ],
];
