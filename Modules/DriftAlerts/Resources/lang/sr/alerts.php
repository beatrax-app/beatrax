<?php

declare(strict_types=1);

return [
    'page_title' => 'Upozorenja o odstupanju',
    'heading' => 'Upozorenja',
    'intro_anomaly' => 'Pojedinačna zaduženja koja za tebe izgledaju neuobičajeno.',
    'intro_drift' => 'Odobrene ponavljajuće serije čije je poslednje zaduženje izašlo izvan tvog praga.',
    'adjust_threshold' => 'Prilagodi prag →',
    'adjust_sensitivity' => 'Prilagodi osetljivost →',

    'type_aria' => 'Tip upozorenja',
    'type' => [
        'drift' => 'Odstupanje pretplate',
        'anomaly' => 'Neuobičajena zaduženja',
    ],

    'lifecycle_aria' => 'Životni ciklus upozorenja',
    'tabs' => [
        'open' => 'Otvoreno',
        'history' => 'Istorija',
        'dismissed' => 'Odbačeno',
    ],

    'load_more' => 'Učitaj još',
    'group_count' => ':count otvoreno odstupanje|:count otvorena odstupanja|:count otvorenih odstupanja',

    'anomaly_empty' => [
        'open_heading' => 'Nema neuobičajenih zaduženja',
        'open_body' => 'Beatrax prati tvoju potrošnju i označava zaduženja koja izgledaju neuobičajeno. Kada stigne nešto neuobičajeno, pojaviće se ovde.',
        'history_heading' => 'Još nema potvrđenih zaduženja',
        'history_body' => 'Zaduženja koja si potvrdio pojaviće se ovde kako bi video šta si već pregledao.',
        'dismissed_heading' => 'Još ništa nije odbačeno',
        'dismissed_body' => 'Kada zaduženje označiš kao očekivano, dolazi ovde sa svojim pravilom izuzimanja.',
    ],

    'empty_open' => [
        'heading' => 'Nema otvorenih upozorenja o odstupanju',
        'body' => 'Beatrax prati tvoje odobrene ponavljajuće serije i označava svaku čije se poslednje zaduženje razlikuje od prethodnog iznosa više od tvog praga. Prilagodi prag u',
        'link' => 'Podešavanja → Podrazumevano upozorenje o odstupanju',
    ],
    'empty_history' => [
        'heading' => 'Još nema potvrđenih odstupanja',
        'body' => 'Potvrđena upozorenja o odstupanju pojaviće se ovde kako bi video šta si već pregledao.',
    ],
    'empty_dismissed' => [
        'heading' => 'Još ništa nije odbačeno',
        'body' => 'Kada Beatraxu kažeš da si otkazao seriju, ta odluka dolazi ovde sa vremenskom oznakom.',
    ],

    'row' => [
        'per_year' => '/god.',
        'meta_prior_now' => 'pre :prior → sada :now',
        'meta_detected' => 'otkriveno :date',
        'meta_threshold' => 'prag ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/god.)',
        'cancel_impact' => 'Otkaži ovo → uštedi :amount/god.',
        'cadence_flipped' => 'Učestalost je promenjena — prikazuje se i u',
        'cadence_flipped_link' => 'Pregled ponavljajućih',
        'acknowledge' => 'Potvrdi',
        'acknowledge_aria' => 'Potvrdi upozorenje o odstupanju :id',
        'snooze' => 'Odloži ▾',
        'snooze_1w' => '1 nedelja',
        'snooze_1m' => '1 mesec',
        'snooze_3m' => '3 meseca',
        'model_cancel' => 'Modeliraj otkazivanje ↗',
        'model_cancel_aria' => 'Modeliraj otkazivanje — modelira otkazivanje u prognozi za upozorenje o odstupanju :id',
        'cancelled' => 'Ovo sam otkazao',
        'cancelled_aria' => 'Ovo sam otkazao — odbacuje upozorenje o odstupanju :id kao otkazano',
    ],

    'toasts' => [
        'acknowledged' => 'Potvrđeno',
        'snoozed' => 'Odloženo',
        'dismissed' => 'Odbačeno',
        'suppression_added' => 'Pravilo izuzimanja je dodato — Opozovi',
        'dismissed_expected' => 'Odbačeno kao očekivano',
        'reopened' => 'Ponovo otvoreno',
        'dismissed_cancelled' => 'Odbačeno kao otkazano',
    ],
];
