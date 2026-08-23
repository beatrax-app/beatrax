<?php

declare(strict_types=1);

return [
    'heading' => 'Valuta računa',
    'intro' => 'Valuta, v kateri je denominiran vsak račun. Nov račun se začne v osnovni valuti.',
    'no_accounts' => 'Zaenkrat ni računov.',
    'legend' => 'Valuta računa :name',
    'label' => 'Valuta',
    'help' => 'Valuta, v kateri ta račun navaja svoje stanje.',
    'save' => 'Shrani valuto',
    'saved' => 'Shranjeno',

    'toast' => [
        'updated' => ':name zdaj navaja zneske v :currency.',
    ],

    'errors' => [
        'unknown' => 'Te valute ta namestitev ne pozna.',
    ],

    'warning' => [
        'intro' => 'Sprememba računa iz :from v :to samo spremeni oznako. Nič shranjenega se ne pretvori niti prepiše.',
        'baseline' => 'Začetno stanje :amount ostane natanko ta znesek in se odslej bere kot :to.',
        'lines' => 'Ta račun trenutno vsebuje:',
        'reads' => 'Po spremembi ta račun navaja svojo vrstico :to — nič, če v :to ne drži ničesar.',
        'confirm' => 'Vseeno spremeni',
        'keep' => 'Obdrži :currency',
    ],
];
