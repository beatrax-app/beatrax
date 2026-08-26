<?php

declare(strict_types=1);

return [
    'heading' => 'Számla pénzneme',
    'intro' => 'Az a pénznem, amelyben az egyes számlák nyilván vannak tartva. Az új számla az alappénznemben indul.',
    'no_accounts' => 'Még nincsenek számlák.',
    'legend' => ':name pénzneme',
    'label' => 'Pénznem',
    'help' => 'Az a pénznem, amelyben ez a számla az egyenlegét mutatja.',
    'save' => 'Pénznem mentése',
    'saved' => 'Mentve',

    'toast' => [
        'updated' => ':name mostantól :currency pénznemben jelenít meg.',
    ],

    'errors' => [
        'unknown' => 'Ezt a pénznemet nem ismeri ez a telepítés.',
    ],

    'warning' => [
        'intro' => 'A számla :from pénznemről :to pénznemre váltása csak átcímkézi. Semmilyen tárolt adat nem kerül átváltásra vagy felülírásra.',
        'baseline' => 'A :amount nyitóegyenleg pontosan ez az összeg marad, és mostantól :to pénznemként olvasandó.',
        'lines' => 'Ez a számla jelenleg ezt tartalmazza:',
        'reads' => 'A változtatás után a számla a :to sorát mutatja — nullát, ha :to pénznemben semmije nincs.',
        'confirm' => 'Váltás mindenképpen',
        'keep' => ':currency megtartása',
    ],
];
