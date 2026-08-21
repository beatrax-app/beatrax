<?php

declare(strict_types=1);

return [
    'page_title' => 'Upozornění',
    'heading' => 'Upozornění',
    'intro_anomaly' => 'Jednotlivé platby, které u tebe vypadají neobvykle.',
    'intro_drift' => 'Schválené opakované série, jejichž poslední platba vybočila z tvého prahu.',
    'adjust_threshold' => 'Upravit práh →',
    'adjust_sensitivity' => 'Upravit citlivost →',

    'type_aria' => 'Typ upozornění',
    'type' => [
        'drift' => 'Odchylka předplatného',
        'anomaly' => 'Neobvyklé platby',
    ],

    'lifecycle_aria' => 'Životní cyklus upozornění',
    'tabs' => [
        'open' => 'Otevřené',
        'history' => 'Historie',
        'dismissed' => 'Zamítnuté',
    ],

    'load_more' => 'Načíst další',
    'group_count' => ':count otevřená odchylka|:count otevřené odchylky|:count otevřených odchylek',

    'anomaly_empty' => [
        'open_heading' => 'Žádné neobvyklé platby',
        'open_body' => 'Beatrax sleduje tvé výdaje a označí platby, které vypadají neobvykle. Až se něco takového objeví, uvidíš to tady.',
        'history_heading' => 'Zatím žádné potvrzené platby',
        'history_body' => 'Platby, které potvrdíš, se objeví tady, ať je vidět, co už máš zkontrolované.',
        'dismissed_heading' => 'Zatím nic zamítnutého',
        'dismissed_body' => 'Když platbu označíš jako očekávanou, přistane tady i s pravidlem pro potlačení.',
    ],

    'empty_open' => [
        'heading' => 'Žádná otevřená upozornění na odchylku',
        'body' => 'Beatrax sleduje tvé schválené opakované série a označí ty, jejichž poslední platba se liší od předchozí částky o víc než tvůj práh. Práh upravíš v',
        'link' => 'Nastavení → Výchozí upozornění na odchylku',
    ],
    'empty_history' => [
        'heading' => 'Zatím žádné potvrzené odchylky',
        'body' => 'Potvrzená upozornění na odchylku se objeví tady, ať je vidět, co už máš zkontrolované.',
    ],
    'empty_dismissed' => [
        'heading' => 'Zatím nic zamítnutého',
        'body' => 'Když v Beatraxu označíš sérii jako zrušenou, přistane to tady i s časovým razítkem.',
    ],

    'row' => [
        'per_year' => '/rok',
        'meta_prior_now' => 'dřív :prior → teď :now',
        'meta_detected' => 'zjištěno :date',
        'meta_threshold' => 'práh ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/rok)',
        'cancel_impact' => 'Zrušit → úspora :amount/rok',
        'cadence_flipped' => 'Změna frekvence — zobrazuje se také v',
        'cadence_flipped_link' => 'Kontrola opakovaných plateb',
        'acknowledge' => 'Potvrdit',
        'acknowledge_aria' => 'Potvrdit upozornění na odchylku :id',
        'snooze' => 'Odložit ▾',
        'snooze_1w' => '1 týden',
        'snooze_1m' => '1 měsíc',
        'snooze_3m' => '3 měsíce',
        'model_cancel' => 'Simulovat zrušení ↗',
        'model_cancel_aria' => 'Simulovat zrušení — promítne zrušení do prognózy pro upozornění na odchylku :id',
        'cancelled' => 'Tohle už je zrušené',
        'cancelled_aria' => 'Tohle už je zrušené — zamítne upozornění na odchylku :id jako zrušené',
    ],

    'toasts' => [
        'acknowledged' => 'Potvrzeno',
        'snoozed' => 'Odloženo',
        'dismissed' => 'Zamítnuto',
        'suppression_added' => 'Pravidlo pro potlačení přidáno — Vrátit zpět',
        'dismissed_expected' => 'Zamítnuto jako očekávané',
        'reopened' => 'Znovu otevřeno',
        'dismissed_cancelled' => 'Zamítnuto jako zrušené',
    ],
];
