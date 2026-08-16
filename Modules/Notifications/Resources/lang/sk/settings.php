<?php

declare(strict_types=1);

return [
    'what_heading' => 'O čom chceš dostávať oznámenia',

    'reminders' => [
        'label' => 'Pripomienky platieb',
        'help' => 'Dostaneš upozornenie skôr, než bude opakovaná platba splatná.',
    ],

    'lead_days' => [
        'label' => 'Pripomenúť ___ dní vopred',
        'help' => 'Koľko dní pred dátumom splatnosti sa pripomienka spustí. 1–30 dní.',
    ],

    'budget_nudges' => [
        'label' => 'Pripomienky k rozpočtu',
        'help' => 'Dostaneš správu, keď bude rozpočet kategórie takmer minutý.',
    ],

    'digest' => [
        'label' => 'Tvoja týždenná situácia',
        'help' => 'Ako často dostávaš súhrn toho, ako to v tomto období vyzerá.',
        'daily' => 'Denne',
        'weekly' => 'Týždenne',
        'off' => 'Vypnuté',
    ],

    'savings' => [
        'label' => 'Tipy na úspory',
        'help' => 'Dostaneš správu, keď Beatrax nájde lacnejší plán alebo miesto, kde sa dá ušetriť.',
    ],

    'when_heading' => 'Kedy a ako',

    'quiet_hours' => [
        'label' => 'Tiché hodiny',
        'help' => 'Počas tohto okna žiadny zvuk ani banner — oznámenia aj tak prídu do schránky.',
        'from' => 'Od',
        'to' => 'Do',
    ],

    'hide_details' => [
        'label' => 'Skryť podrobnosti v oznámeniach',
        'help' => 'Zobrazovať sumy a mená obchodníkov priamo v banneri oznámenia. Vypni to, ak ti na obrazovku môžu vidieť iní.',
    ],

    'save' => 'Uložiť nastavenia oznámení',
    'saved' => 'Uložené.',

    'other_devices' => [
        'summary' => 'Ostatné zariadenia',
        'empty' => 'Zatiaľ nie sú spárované žiadne ďalšie zariadenia.',
        'unnamed' => 'Nepomenované zariadenie',

        'summary_line' => 'pripomienky :reminders · rozpočet :nudges · súhrn :digest · úspory :savings',
        'on' => 'zap.',
        'off' => 'vyp.',
    ],

    'errors' => [
        'save_failed' => 'Nastavenia oznámení sa nepodarilo uložiť. Skús to znova.',
    ],
];
