<?php

declare(strict_types=1);

return [
    'page_title' => 'Opozorila',
    'heading' => 'Opozorila',
    'intro_anomaly' => 'Posamezne bremenitve, ki so zate videti nenavadne.',
    'intro_drift' => 'Odobrene ponavljajoče serije, pri katerih je zadnja bremenitev presegla tvoj prag.',
    'adjust_threshold' => 'Prilagodi prag →',
    'adjust_sensitivity' => 'Prilagodi občutljivost →',

    'type_aria' => 'Vrsta opozorila',
    'type' => [
        'drift' => 'Odstopanje naročnine',
        'anomaly' => 'Nenavadne bremenitve',
    ],

    'lifecycle_aria' => 'Življenjski cikel opozorila',
    'tabs' => [
        'open' => 'Odprto',
        'history' => 'Zgodovina',
        'dismissed' => 'Opuščeno',
    ],

    'load_more' => 'Naloži več',
    'group_count' => ':count odprto odstopanje|:count odprti odstopanji|:count odprta odstopanja|:count odprtih odstopanj',

    'anomaly_empty' => [
        'open_heading' => 'Ni nenavadnih bremenitev',
        'open_body' => 'Beatrax spremlja tvojo porabo in označi bremenitve, ki so videti nenavadne. Ko prispe nekaj nenavadnega, se prikaže tukaj.',
        'history_heading' => 'Potrjenih bremenitev še ni',
        'history_body' => 'Bremenitve, ki si jih potrdil, se prikažejo tukaj, da vidiš, kaj si že pregledal.',
        'dismissed_heading' => 'Opuščenega še ni nič',
        'dismissed_body' => 'Ko bremenitev označiš kot pričakovano, pristane tukaj skupaj s pravilom izključevanja.',
    ],

    'empty_open' => [
        'heading' => 'Ni odprtih opozoril o odstopanju',
        'body' => 'Beatrax spremlja tvoje odobrene ponavljajoče serije in označi vsako, pri kateri se zadnja bremenitev od prejšnjega zneska razlikuje bolj, kot dovoljuje tvoj prag. Prag prilagodiš v',
        'link' => 'Nastavitve → Privzeto opozorilo o odstopanju',
    ],
    'empty_history' => [
        'heading' => 'Potrjenih odstopanj še ni',
        'body' => 'Potrjena opozorila o odstopanju se prikažejo tukaj, da vidiš, kaj si že pregledal.',
    ],
    'empty_dismissed' => [
        'heading' => 'Opuščenega še ni nič',
        'body' => 'Ko Beatraxu poveš, da si preklical serijo, ta odločitev pristane tukaj s časovnim žigom.',
    ],

    'row' => [
        'per_year' => '/leto',
        'meta_prior_now' => 'prej :prior → zdaj :now',
        'meta_detected' => 'zaznano :date',
        'meta_threshold' => 'prag ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/leto)',
        'cancel_impact' => 'Prekliči to → prihrani :amount/leto',
        'cadence_flipped' => 'Pogostost se je spremenila — prikazuje se tudi v',
        'cadence_flipped_link' => 'Pregled ponavljajočih se',
        'acknowledge' => 'Potrdi',
        'acknowledge_aria' => 'Potrdi opozorilo o odstopanju :id',
        'snooze' => 'Odloži ▾',
        'snooze_1w' => '1 teden',
        'snooze_1m' => '1 mesec',
        'snooze_3m' => '3 meseci',
        'model_cancel' => 'Modeliraj preklic ↗',
        'model_cancel_aria' => 'Modeliraj preklic — v napovedi modelira preklic za opozorilo o odstopanju :id',
        'cancelled' => 'To sem preklical',
        'cancelled_aria' => 'To sem preklical — opozorilo o odstopanju :id opusti kot preklicano',
    ],

    'toasts' => [
        'acknowledged' => 'Potrjeno',
        'snoozed' => 'Odloženo',
        'dismissed' => 'Opuščeno',
        'suppression_added' => 'Pravilo izključevanja je dodano — Razveljavi',
        'dismissed_expected' => 'Opuščeno kot pričakovano',
        'reopened' => 'Znova odprto',
        'dismissed_cancelled' => 'Opuščeno kot preklicano',
    ],
];
