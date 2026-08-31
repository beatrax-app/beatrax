<?php

declare(strict_types=1);

return [
    'heading' => 'Áttekintés',
    'subtitle' => 'Az alkalmazásba épített Developer Console működési felülete.',
    'worker_heartbeat' => 'Worker életjel',
    'not_running' => 'NEM FUT',
    // i18n-review: hu · heartbeat_age — same instrumental suffix as the sidebar
    // pulse it sits beside, and both want the same answer. `ttl` is left as the
    // technical abbreviation, beside a count it does not govern.
    'heartbeat_age' => ':count s-mal ezelőtt · ttl :ttl s|:count s-mal ezelőtt · ttl :ttl s',
    'queue' => 'Várólista',
    'pending' => 'függőben',
    'failed' => 'sikertelen',
    'batches' => 'köteg',
    'queue_summary' => ':failed · :batches',
    'queue_summary_failed' => ':count sikertelen feladat|:count sikertelen feladat',
    'queue_summary_batches' => ':count aktív köteg|:count aktív köteg',
    'last_command' => 'Utolsó parancs',
    'waiting_for_logs' => 'Várakozás naplósorokra…',
    'recent_runs' => 'Legutóbbi futtatások',
    'recent_runs_empty' => 'Még nincs futtatás. Nyomd meg a ⌘K-t egy parancs futtatásához.',
    'open_alerts' => 'Nyitott riasztások',
    'open_alerts_empty' => 'Nincs nyitott riasztás.',
    'reauth' => 'Újrahitelesítés →',
];
