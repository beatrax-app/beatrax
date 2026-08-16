<?php

declare(strict_types=1);

return [
    'page_title' => 'Upozornenia',
    'heading' => 'Upozornenia',
    'intro_anomaly' => 'Jednotlivé platby, ktoré u teba vyzerajú nezvyčajne.',
    'intro_drift' => 'Schválené opakované série, ktorých posledná platba vyšla mimo tvojho prahu.',
    'adjust_threshold' => 'Upraviť prah →',
    'adjust_sensitivity' => 'Upraviť citlivosť →',

    'type_aria' => 'Typ upozornenia',
    'type' => [
        'drift' => 'Odchýlka predplatného',
        'anomaly' => 'Nezvyčajné platby',
    ],

    'lifecycle_aria' => 'Životný cyklus upozornenia',
    'tabs' => [
        'open' => 'Otvorené',
        'history' => 'História',
        'dismissed' => 'Zamietnuté',
    ],

    'load_more' => 'Načítať ďalšie',
    'group_count' => 'Otvorené odchýlky: :count',

    'anomaly_empty' => [
        'open_heading' => 'Žiadne nezvyčajné platby',
        'open_body' => 'Beatrax sleduje tvoje výdavky a označí platby, ktoré vyzerajú nezvyčajne. Keď niečo nezvyčajné pribudne, objaví sa to tu.',
        'history_heading' => 'Zatiaľ žiadne potvrdené platby',
        'history_body' => 'Potvrdené platby sa objavia tu, takže máš prehľad o tom, čo je už skontrolované.',
        'dismissed_heading' => 'Zatiaľ nič zamietnuté',
        'dismissed_body' => 'Keď platbu označíš ako očakávanú, pristane tu spolu so svojím pravidlom potlačenia.',
    ],

    'empty_open' => [
        'heading' => 'Žiadne otvorené upozornenia na odchýlku',
        'body' => 'Beatrax sleduje tvoje schválené opakované série a označí tie, ktorých posledná platba sa líši od predchádzajúcej sumy viac, než je tvoj prah. Prah upravíš v',
        'link' => 'Nastavenia → Predvolené upozornenie na odchýlku',
    ],
    'empty_history' => [
        'heading' => 'Zatiaľ žiadne potvrdené odchýlky',
        'body' => 'Potvrdené upozornenia na odchýlku sa objavia tu, takže máš prehľad o tom, čo je už skontrolované.',
    ],
    'empty_dismissed' => [
        'heading' => 'Zatiaľ nič zamietnuté',
        'body' => 'Keď v Beatraxe označíš sériu ako zrušenú, toto rozhodnutie pristane tu spolu s časovou pečiatkou.',
    ],

    'row' => [
        'per_year' => '/rok',
        'meta_prior_now' => 'predtým :prior → teraz :now',
        'meta_detected' => 'zistené :date',
        'meta_threshold' => 'prah ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/rok)',
        'cancel_impact' => 'Zruš to → ušetríš :amount/rok',
        'cadence_flipped' => 'Zmena frekvencie — zobrazuje sa aj v',
        'acknowledge' => 'Potvrdiť',
        'acknowledge_aria' => 'Potvrdiť upozornenie na odchýlku :id',
        'snooze' => 'Odložiť ▾',
        'snooze_1w' => '1 týždeň',
        'snooze_1m' => '1 mesiac',
        'snooze_3m' => '3 mesiace',
        'model_cancel' => 'Simulovať zrušenie ↗',
        'model_cancel_aria' => 'Simulovať zrušenie — zahrnie zrušenie do prognózy pre upozornenie na odchýlku :id',
        'cancelled' => 'Už je to zrušené',
        'cancelled_aria' => 'Už je to zrušené — zamietne upozornenie na odchýlku :id ako zrušené',
    ],

    'toasts' => [
        'acknowledged' => 'Potvrdené',
        'snoozed' => 'Odložené',
        'dismissed' => 'Zamietnuté',
        'suppression_added' => 'Pravidlo potlačenia pridané — Späť',
        'dismissed_expected' => 'Zamietnuté ako očakávané',
        'reopened' => 'Znova otvorené',
        'dismissed_cancelled' => 'Zamietnuté ako zrušené',
    ],
];
