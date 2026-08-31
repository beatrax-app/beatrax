<?php

declare(strict_types=1);

return [
    'page_title' => 'Upozorenja o odstupanju',
    'intro_anomaly' => 'Pojedinačna terećenja koja za tebe izgledaju neuobičajeno.',
    'intro_drift' => 'Odobrene ponavljajuće serije čije je posljednje terećenje izašlo izvan tvojeg praga.',
    'adjust_threshold' => 'Prilagodi prag →',
    'adjust_sensitivity' => 'Prilagodi osjetljivost →',

    'type_aria' => 'Vrsta upozorenja',
    'type' => [
        'drift' => 'Odstupanje pretplate',
        'anomaly' => 'Neuobičajena terećenja',
    ],

    'lifecycle_aria' => 'Životni ciklus upozorenja',
    'tabs' => [
        'open' => 'Otvoreno',
        'history' => 'Povijest',
        'dismissed' => 'Odbačeno',
    ],

    'load_more' => 'Učitaj još',
    'group_count' => ':count otvoreno odstupanje|:count otvorena odstupanja|:count otvorenih odstupanja',

    'anomaly_empty' => [
        'open_heading' => 'Nema neuobičajenih terećenja',
        'open_body' => 'Beatrax prati tvoju potrošnju i označava terećenja koja izgledaju neuobičajeno. Kada stigne nešto neuobičajeno, pojavit će se ovdje.',
        'history_heading' => 'Još nema potvrđenih terećenja',
        'history_body' => 'Terećenja koja si potvrdio pojavit će se ovdje kako bi vidio što si već pregledao.',
        'dismissed_heading' => 'Još ništa nije odbačeno',
        'dismissed_body' => 'Kada terećenje označiš kao očekivano, dolazi ovdje sa svojim pravilom izuzimanja.',
    ],

    'empty_open' => [
        'heading' => 'Nema otvorenih upozorenja o odstupanju',
        'body' => 'Beatrax prati tvoje odobrene ponavljajuće serije i označava svaku čije se posljednje terećenje razlikuje od prethodnog iznosa više od tvojeg praga. Prilagodi prag u',
        'link' => 'Postavke → Zadano upozorenje o odstupanju',
    ],
    'empty_history' => [
        'heading' => 'Još nema potvrđenih odstupanja',
        'body' => 'Potvrđena upozorenja o odstupanju pojavit će se ovdje kako bi vidio što si već pregledao.',
    ],
    'empty_dismissed' => [
        'heading' => 'Još ništa nije odbačeno',
        'body' => 'Kada Beatraxu kažeš da si otkazao seriju, ta odluka dolazi ovdje s vremenskom oznakom.',
    ],

    'row' => [
        'per_year' => '/god.',
        'meta_prior_now' => 'prije :prior → sada :now',
        'meta_detected' => 'otkriveno :date',
        'meta_threshold' => 'prag ±:percent %',
        'meta_eur_equiv' => '(≈ :amount/god.)',
        'cancel_impact' => 'Otkaži ovo → uštedi :amount/god.',
        'cadence_flipped' => 'Učestalost je promijenjena — prikazuje se i u',
        'cadence_flipped_link' => 'Pregled ponavljajućih',
        'acknowledge' => 'Potvrdi',
        'acknowledge_aria' => 'Potvrdi upozorenje o odstupanju :id',
        'snooze' => 'Odgodi ▾',
        'snooze_1w' => '1 tjedan',
        'snooze_1m' => '1 mjesec',
        'snooze_3m' => '3 mjeseca',
        'model_cancel' => 'Modeliraj otkazivanje ↗',
        'model_cancel_aria' => 'Modeliraj otkazivanje — modelira otkazivanje u prognozi za upozorenje o odstupanju :id',
        'cancelled' => 'Ovo sam otkazao',
        'cancelled_aria' => 'Ovo sam otkazao — odbacuje upozorenje o odstupanju :id kao otkazano',
    ],

    'toasts' => [
        'gone' => 'To upozorenje više ne postoji.',
        'acknowledged' => 'Potvrđeno',
        'snoozed' => 'Odgođeno',
        'dismissed' => 'Odbačeno',
        'suppression_added' => 'Pravilo izuzimanja je dodano — Poništi',
        'dismissed_expected' => 'Odbačeno kao očekivano',
        'reopened' => 'Ponovno otvoreno',
        'dismissed_cancelled' => 'Odbačeno kao otkazano',
    ],
];
