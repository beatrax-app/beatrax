<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Imports pabeigts',
        'receipts' => 'Atrasti jauni čeki',
        'drift' => 'Mainījies regulārs maksājums',
        'forecast' => 'Priekšā naudas plūsmas iztrūkums',
        'budget_nudge' => 'Budžets gandrīz iztērēts',
        'savings_prompt' => 'Ir lētāks plāns',
        'ics_statement_ready' => 'Gatavs jauns ICS konta izraksts',
        'payment_reminder_confident' => 'Maksājuma termiņš: :day',
        'payment_reminder_hedged' => 'Maksājuma termiņš: ap :day',
        'position_digest_daily' => 'Jūsu dienas situācija',
        'position_digest_weekly' => 'Jūsu nedēļas situācija',
    ],

    'body' => [
        'budget_nudge' => ':category — iztērēts :spent no :budget.',
        'receipts_matched' => 'No jūsu e-pasta sasaistīti :count čeku.|No jūsu e-pasta sasaistīts :count čeks.|No jūsu e-pasta sasaistīti :count čeki.',
        'import_finished' => 'Importēti :count darījumu.|Importēts :count darījums.|Importēti :count darījumi.',
        'drift' => 'Regulārs maksājums mainījies :direction par :delta :currency.',
        'forecast' => 'Jūsu prognozētais atlikums tuvāko 30 dienu laikā noslīd zem nulles.',
        'ics_statement_ready' => 'Lejupielādējiet to no ICS portāla un ievelciet Beatrax, lai šīs kartes tēriņi būtu aktuāli.',
        'payment_reminder_hedged' => ':name — gaidāms ap :day, :amount.',
        'payment_reminder_confident' => ':name — termiņš :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/mēn.)',
    ],

    'drift_direction' => [
        'up' => 'uz augšu',
        'down' => 'uz leju',
    ],

    'digest' => [
        'nothing_notable' => 'Nekas neprasa jūsu uzmanību.',
        'flow' => 'Ieņēmumi :in, izdevumi :out, neto :net.',
        'over_budget' => 'Pārsniegts budžets: :amount.',
        'payments_due' => 'Šajā periodā :count maksājumu.|Šajā periodā :count maksājums.|Šajā periodā :count maksājumi.',
        'shortfall' => 'Priekšā naudas plūsmas iztrūkums.',
    ],
];
