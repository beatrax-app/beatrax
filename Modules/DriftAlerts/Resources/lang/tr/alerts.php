<?php

declare(strict_types=1);

return [
    'page_title' => 'Sapma uyarıları',
    'heading' => 'Uyarılar',
    'intro_anomaly' => 'Senin için olağan dışı görünen tekil harcamalar.',
    'intro_drift' => 'Son harcaması eşiğinin dışına çıkan onaylı düzenli seriler.',
    'adjust_threshold' => 'Eşiği ayarla →',
    'adjust_sensitivity' => 'Duyarlılığı ayarla →',

    'type_aria' => 'Uyarı türü',
    'type' => [
        'drift' => 'Abonelik sapması',
        'anomaly' => 'Olağan dışı harcamalar',
    ],

    'lifecycle_aria' => 'Uyarı yaşam döngüsü',
    'tabs' => [
        'open' => 'Açık',
        'history' => 'Geçmiş',
        'dismissed' => 'Kapatılan',
    ],

    'load_more' => 'Daha fazla yükle',
    'group_count' => ':count sapma açık',

    'anomaly_empty' => [
        'open_heading' => 'Olağan dışı harcama yok',
        'open_body' => 'Beatrax harcamalarını izler ve olağan dışı görünen harcamaları işaretler. Olağan dışı bir şey geldiğinde burada görünür.',
        'history_heading' => 'Henüz onaylanmış harcama yok',
        'history_body' => 'Onayladığın harcamalar, neleri incelediğini görebilmen için burada görünür.',
        'dismissed_heading' => 'Henüz kapatılan bir şey yok',
        'dismissed_body' => 'Bir harcamayı beklenen olarak işaretlediğinde, bastırma kuralıyla birlikte burada görünür.',
    ],

    'empty_open' => [
        'heading' => 'Açık sapma uyarısı yok',
        'body' => 'Beatrax onaylı düzenli serilerini izler ve son harcaması önceki tutardan eşiğinden daha fazla farklılaşan serileri işaretler. Eşiği şuradan ayarla:',
        'link' => 'Ayarlar → Varsayılan sapma uyarısı',
    ],
    'empty_history' => [
        'heading' => 'Henüz onaylanmış sapma yok',
        'body' => 'Onayladığın sapma uyarıları, neleri incelediğini görebilmen için burada görünür.',
    ],
    'empty_dismissed' => [
        'heading' => 'Henüz kapatılan bir şey yok',
        'body' => "Bir seriyi iptal ettiğini Beatrax'a bildirdiğinde, bu karar zaman damgasıyla birlikte burada görünür.",
    ],

    'row' => [
        'per_year' => '/yıl',
        'meta_prior_now' => 'önceki :prior → şimdi :now',
        'meta_detected' => ':date tarihinde algılandı',
        'meta_threshold' => 'eşik ±%:percent',
        'meta_eur_equiv' => '(≈ :amount/yıl)',
        'cancel_impact' => 'Bunu iptal et → :amount/yıl tasarruf et',
        'cadence_flipped' => 'Sıklık değişti — şurada da görünüyor:',
        'cadence_flipped_link' => 'Düzenli işlemleri gözden geçir',
        'acknowledge' => 'Onayla',
        'acknowledge_aria' => ':id numaralı sapma uyarısını onayla',
        'snooze' => 'Ertele ▾',
        'snooze_1w' => '1 hafta',
        'snooze_1m' => '1 ay',
        'snooze_3m' => '3 ay',
        'model_cancel' => 'İptali modelle ↗',
        'model_cancel_aria' => 'İptali modelle — :id numaralı sapma uyarısı için iptali tahminde modeller',
        'cancelled' => 'Bunu iptal ettim',
        'cancelled_aria' => 'Bunu iptal ettim — :id numaralı sapma uyarısını iptal edildi olarak kapatır',
    ],

    'toasts' => [
        'acknowledged' => 'Onaylandı',
        'snoozed' => 'Ertelendi',
        'dismissed' => 'Kapatıldı',
        'suppression_added' => 'Bastırma kuralı eklendi — Geri al',
        'dismissed_expected' => 'Beklenen olarak kapatıldı',
        'reopened' => 'Yeniden açıldı',
        'dismissed_cancelled' => 'İptal edildi olarak kapatıldı',
    ],
];
