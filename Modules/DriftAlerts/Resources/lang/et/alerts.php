<?php

declare(strict_types=1);

return [
    'page_title' => 'Muutuste hoiatused',
    'heading' => 'Hoiatused',
    'intro_anomaly' => 'Üksikud maksed, mis sinu jaoks tavapärasest erinevad.',
    'intro_drift' => 'Kinnitatud korduvmaksete seeriad, mille viimane makse väljus sinu läve piiridest.',
    'adjust_threshold' => 'Kohanda läve →',
    'adjust_sensitivity' => 'Kohanda tundlikkust →',

    'type_aria' => 'Hoiatuse tüüp',
    'type' => [
        'drift' => 'Tellimuste hinnamuutus',
        'anomaly' => 'Ebatavalised maksed',
    ],

    'lifecycle_aria' => 'Hoiatuse elukäik',
    'tabs' => [
        'open' => 'Lahtised',
        'history' => 'Ajalugu',
        'dismissed' => 'Peidetud',
    ],

    'load_more' => 'Laadi veel',
    'group_count' => ':count muutus lahti|:count muutust lahti',

    'anomaly_empty' => [
        'open_heading' => 'Ebatavalisi makseid pole',
        'open_body' => 'Beatrax jälgib sinu kulutusi ja märgistab maksed, mis tunduvad tavapärasest erinevad. Kui midagi ebatavalist saabub, ilmub see siia.',
        'history_heading' => 'Teadmiseks võetud makseid veel pole',
        'history_body' => 'Maksed, mille oled teadmiseks võtnud, ilmuvad siia, et näeksid, mida oled juba üle vaadanud.',
        'dismissed_heading' => 'Midagi pole veel peidetud',
        'dismissed_body' => 'Kui märgid makse oodatuks, jõuab see koos oma summutusreegliga siia.',
    ],

    'empty_open' => [
        'heading' => 'Lahtisi muutuse hoiatusi pole',
        'body' => 'Beatrax jälgib sinu kinnitatud korduvmaksete seeriaid ja märgistab need, mille viimane makse erineb eelmisest summast rohkem, kui sinu lävi lubab. Läve saad kohandada siin:',
        'link' => 'Seaded → Muutuse hoiatuse vaikimisi lävi',
    ],
    'empty_history' => [
        'heading' => 'Teadmiseks võetud muutusi veel pole',
        'body' => 'Teadmiseks võetud muutuse hoiatused ilmuvad siia, et näeksid, mida oled juba üle vaadanud.',
    ],
    'empty_dismissed' => [
        'heading' => 'Midagi pole veel peidetud',
        'body' => 'Kui annad Beatraxile teada, et oled seeria üles öelnud, jõuab see otsus koos ajatempliga siia.',
    ],

    'row' => [
        'per_year' => '/a',
        'meta_prior_now' => 'varem :prior → nüüd :now',
        'meta_detected' => 'tuvastatud :date',
        'meta_threshold' => 'lävi ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/a)',
        'cancel_impact' => 'Ütle see üles → säästad :amount/a',
        'cadence_flipped' => 'Maksesagedus muutus — kuvatakse ka jaotises',
        'cadence_flipped_link' => 'Vaata korduvmaksed üle',
        'acknowledge' => 'Võta teadmiseks',
        'acknowledge_aria' => 'Võta teadmiseks muutuse hoiatus :id',
        'snooze' => 'Lükka edasi ▾',
        'snooze_1w' => '1 nädal',
        'snooze_1m' => '1 kuu',
        'snooze_3m' => '3 kuud',
        'model_cancel' => 'Modelleeri ülesütlemine ↗',
        'model_cancel_aria' => 'Modelleeri ülesütlemine — arvestab prognoosis ülesütlemist muutuse hoiatuse :id puhul',
        'cancelled' => 'Ütlesin selle üles',
        'cancelled_aria' => 'Ütlesin selle üles — peidab muutuse hoiatuse :id ülesöelduna',
    ],

    'toasts' => [
        'acknowledged' => 'Teadmiseks võetud',
        'snoozed' => 'Edasi lükatud',
        'dismissed' => 'Peidetud',
        'suppression_added' => 'Summutusreegel lisatud — Võta tagasi',
        'dismissed_expected' => 'Peidetud oodatuna',
        'reopened' => 'Uuesti avatud',
        'dismissed_cancelled' => 'Peidetud ülesöelduna',
    ],
];
