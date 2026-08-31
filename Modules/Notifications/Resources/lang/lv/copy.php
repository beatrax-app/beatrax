<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Imports pabeigts',
        'receipts' => 'Atrasti jauni čeki',
        'manual_entry' => 'Kases grāmata atjaunināta',
        'migration_finished' => 'Migrācija pabeigta',
        'drift' => 'Mainījies regulārs maksājums',
        'forecast' => 'Priekšā naudas plūsmas iztrūkums',
        'budget_nudge' => 'Budžets gandrīz iztērēts',
        'budget_nudge_spent' => 'Budžets iztērēts',
        'budget_nudge_over' => 'Budžets pārsniegts',
        'savings_prompt' => 'Vieta, kur jūs varētu ietaupīt',
        'ics_statement_ready' => 'Gatavs jauns ICS konta izraksts',
        'payment_reminder_confident' => 'Maksājuma termiņš: :day (:date)',
        'payment_reminder_hedged' => 'Maksājuma termiņš: ap :day (:date)',
        'position_digest_daily' => 'Jūsu dienas situācija',
        'position_digest_weekly' => 'Jūsu nedēļas situācija',
    ],

    'body' => [
        'budget_nudge' => ':category — iztērēts :spent no :budget.',
        'receipts_matched' => 'No jūsu e-pasta sasaistīti :count čeku.|No jūsu e-pasta sasaistīts :count čeks.|No jūsu e-pasta sasaistīti :count čeki.',
        'import_finished' => 'Importēti :count darījumu.|Importēts :count darījums.|Importēti :count darījumi.',
        'manual_entry' => 'Ar roku pievienoti :count ierakstu.|Ar roku pievienots :count ieraksts.|Ar roku pievienoti :count ieraksti.',
        'migration_finished' => 'Jūsu budžets ir pārnests, ieskaitot :count darījumu.|Jūsu budžets ir pārnests, ieskaitot :count darījumu.|Jūsu budžets ir pārnests, ieskaitot :count darījumus.',
        'drift' => 'Regulārs maksājums mainījies :direction par :amount.',
        'forecast' => 'Jūsu prognozētais atlikums :date noslīd zem nulles.',
        'forecast_buffer' => 'Jūsu prognozētais atlikums :date noslīd zem jūsu :buffer rezerves.',
        'ics_statement_ready' => 'Lejupielādējiet to no ICS portāla un ievelciet Beatrax, lai šīs kartes tēriņi būtu aktuāli.',
        'payment_reminder_hedged' => ':name — gaidāms ap :day (:date), :amount.',
        'payment_reminder_confident' => ':name — termiņš :day (:date), :amount.',
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
