<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Citlivosť upozornení',
    'sensitivity_help' => 'Označí platby, ktoré sú o viac ako :percent % vyššie než tvoje bežné výdavky u daného obchodníka alebo v danej kategórii.',

    'min_amount_label' => 'Minimálna suma platby',
    'min_amount_help' => 'Ignorovať anomálie pri platbách pod touto sumou. Ukladá sa v centoch (€) — 1000 znamená 10,00 €.',

    'save' => 'Uložiť nastavenia anomálií',
    'saved' => 'Uložené.',

    'suppression' => [
        'summary' => 'Pravidlá potlačenia',
        'empty' => 'Zatiaľ žiadne pravidlá potlačenia. Keď platbu označíš ako očakávanú, pravidlo sa objaví tu.',
        'remove' => 'Odstrániť',
        'remove_aria' => 'Odstrániť pravidlo potlačenia',
        'removed_toast' => 'Pravidlo odstránené',
    ],

    'unknown_merchant' => 'Neznámy obchodník',

    'detectors' => [
        'large' => 'Vysoká platba',
        'first_time' => 'Prvýkrát',
        'duplicate' => 'Duplikát',
    ],

    'errors' => [
        'sensitivity_range' => 'Citlivosť musí byť medzi 1 a 100.',
        'min_amount_negative' => 'Minimálna suma platby nemôže byť záporná.',
    ],
];
