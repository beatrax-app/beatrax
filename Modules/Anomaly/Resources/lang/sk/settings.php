<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Citlivosť upozornení',
    'sensitivity_help' => 'Ako ľahko Beatrax označí platbu za neobvyklú u daného obchodníka alebo v danej kategórii, od 1 do 100. Vyššia hodnota označí viac.',

    'min_amount_label' => 'Minimálna suma platby',
    'min_amount_help' => 'Ignorovať anomálie pri platbách pod touto sumou. Ukladá sa v centoch (:symbol) — 1000 znamená :example.',

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
