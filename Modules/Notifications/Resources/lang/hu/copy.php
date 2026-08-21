<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Az import befejeződött',
        'receipts' => 'Új bizonylatok találhatók',
        'drift' => 'Egy ismétlődő terhelés megváltozott',
        'forecast' => 'Pénzforgalmi hiány várható',
        'budget_nudge' => 'A költségvetés majdnem elfogyott',
        'savings_prompt' => 'Létezik olcsóbb csomag',
        'ics_statement_ready' => 'Új ICS-kivonat érhető el',
        'payment_reminder_confident' => 'Fizetés esedékes: :day',
        'payment_reminder_hedged' => 'Fizetés esedékes :day körül',
        'position_digest_daily' => 'A napi helyzeted',
        'position_digest_weekly' => 'A heti helyzeted',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent elköltve ebből: :budget.',
        'receipts_matched' => ':count bizonylat párosítva az e-mailjeidből.|:count bizonylat párosítva az e-mailjeidből.',
        'import_finished' => ':count tranzakció importálva.|:count tranzakció importálva.',
        'drift' => 'Egy ismétlődő terhelés :amount összeggel :direction.',
        'forecast' => 'Az előrejelzett egyenleged 30 napon belül nulla alá csökken.',
        'ics_statement_ready' => 'Töltsd le az ICS-portálról, és húzd be a Beatraxba, hogy naprakész maradjon ennek a kártyának a költése.',
        'payment_reminder_hedged' => ':name — várhatóan :day körül, :amount.',
        'payment_reminder_confident' => ':name — esedékes: :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/hó)',
    ],

    'drift_direction' => [
        'up' => 'nőtt',
        'down' => 'csökkent',
    ],

    'digest' => [
        'nothing_notable' => 'Semmi nem igényel figyelmet.',
        'flow' => 'Be: :in, ki: :out, nettó: :net.',
        'over_budget' => 'Eddig :amount túllépés a költségvetésen.',
        'payments_due' => 'Ebben az időszakban :count fizetés esedékes.|Ebben az időszakban :count fizetés esedékes.',
        'shortfall' => 'Pénzforgalmi hiány várható.',
    ],
];
