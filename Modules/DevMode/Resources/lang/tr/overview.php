<?php

declare(strict_types=1);

return [
    'heading' => 'Genel bakış',
    'subtitle' => 'Uygulama içi Developer Console için operasyonel görünüm.',
    'worker_heartbeat' => 'Worker sinyali',
    'not_running' => 'ÇALIŞMIYOR',
    'queue' => 'Kuyruk',
    'pending' => 'bekleyen',
    'failed' => 'başarısız',
    'batches' => 'batch',
    'queue_summary' => ':failed · :batches',
    'queue_summary_failed' => ':count başarısız iş',
    // i18n-review: tr · queue_summary_batches — "batch" had been left in English.
    // "Toplu iş" is the term Turkish Laravel writing uses, but it sits close to
    // the "iş" already standing for a job, so a native developer should confirm.
    'queue_summary_batches' => ':count etkin toplu iş',
    'last_command' => 'Son komut',
    'waiting_for_logs' => 'Log satırları bekleniyor…',
    'recent_runs' => 'Son çalıştırmalar',
    'recent_runs_empty' => 'Henüz çalıştırma yok. Komut çalıştırmak için ⌘K tuşlarına bas.',
    'open_alerts' => 'Açık uyarılar',
    'open_alerts_empty' => 'Açık uyarı yok.',
    'reauth' => 'Yeniden kimlik doğrula →',
];
