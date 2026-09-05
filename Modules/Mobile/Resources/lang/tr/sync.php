<?php

declare(strict_types=1);

return [
    'page_title' => 'Veriler ve cihazlar',
    'heading' => 'Veriler ve cihazlar',
    'sync_status' => 'Senkronizasyon durumu',
    'syncing_progress' => 'Senkronize ediliyor… :count kayıt',
    'initial_sync_aria' => 'İlk senkronizasyon ilerlemesi',
    'no_peers' => 'Senkronizasyona başlamak için başka bir cihaz eşleştir.',
    'sync_now' => 'Şimdi senkronize et',
    'result' => [
        'synced' => 'Diğer cihazınla senkronize edildi.',
        'unreachable' => 'Diğer cihazına ulaşılamadı — ikisinin de aynı ağda olduğundan emin ol.',
        'locked' => 'Senkronize etmek için uygulamanın kilidini aç.',
        'not_enabled' => 'Senkronizasyon bu cihazda henüz kurulmadı.',
        'unreadable' => 'Bu cihazın anahtarı artık açılmıyor. Senkronizasyonu sürdürmek için yeniden eşleştir.',
        'paused_on_cellular' => 'Duraklatıldı — senkronizasyon yalnızca Wi-Fi ile sınırlı ve mobil veri kullanıyorsun.',
    ],
    'background_note' => 'Beatrax açık olduğu sürece dinlemeye devam eder, böylece eşleştirilmiş bir cihaz bununla istediği zaman senkronize olabilir. Şimdi senkronize et düğmesi, veri alışverişini bu taraftan başlatır.',
    'background_note_phone' => 'Senkronizasyon, Şimdi senkronize et dokunduğunda gerçekleşir. Arka planda çalışamaz — uygulama kilidi tek anahtarı tutuyor.',
    'network' => 'Ağ',
    'pause_cellular' => 'Mobil veride senkronizasyonu duraklat',
    'pause_cellular_help' => 'Varsayılan olarak kapalı — senkronizasyon her yerde çalışır. Yalnızca Wi-Fi üzerinden senkronize etmek için aç.',
];
