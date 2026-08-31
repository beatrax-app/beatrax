<?php

declare(strict_types=1);

return [
    'heading' => 'Prezentare generală',
    'subtitle' => 'Suprafața operațională pentru Developer Console din aplicație.',
    'worker_heartbeat' => 'Puls worker',
    'not_running' => 'NU RULEAZĂ',
    // i18n-review: ro · heartbeat_age — the 20-and-up arm writes «de» before the
    // unit symbol, the way a spelled-out noun requires. Whether Romanian keeps
    // «de» in front of an abbreviation like «s» is a native reader's call.
    'heartbeat_age' => 'acum :count s · ttl :ttl s|acum :count s · ttl :ttl s|acum :count de s · ttl :ttl s',
    'queue' => 'Coadă',
    'pending' => 'în așteptare',
    'failed' => 'eșuate',
    'batches' => 'loturi',
    'queue_summary' => ':failed · :batches',
    'queue_summary_failed' => ':count sarcină eșuată|:count sarcini eșuate|:count de sarcini eșuate',
    'queue_summary_batches' => ':count lot activ|:count loturi active|:count de loturi active',
    'last_command' => 'Ultima comandă',
    'waiting_for_logs' => 'Se așteaptă rânduri de jurnal…',
    'recent_runs' => 'Rulări recente',
    'recent_runs_empty' => 'Nicio rulare încă. Apasă ⌘K ca să rulezi o comandă.',
    'open_alerts' => 'Alerte deschise',
    'open_alerts_empty' => 'Nicio alertă deschisă.',
    'reauth' => 'Reautentificare →',
];
