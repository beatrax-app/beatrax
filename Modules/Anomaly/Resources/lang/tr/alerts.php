<?php

declare(strict_types=1);

return [
    'unknown_merchant' => 'Bilinmeyen işyeri',

    'reasons' => [
        'large' => 'Yüksek harcama',
        'first_time' => 'İlk kez',
        'duplicate' => 'Yinelenen',
    ],

    'reason_aria' => [
        'first_time' => 'Neden: ilk kez görülen işyeri',
        'duplicate' => 'Neden: yinelenen harcama',
        'generic' => 'Neden: :label',
    ],

    'baseline_to_actual' => 'referans :baseline → gerçekleşen: :actual',
    'charged' => 'tahsil edildi :actual',
    'detected' => ':date tarihinde algılandı',
    'sensitivity' => 'duyarlılık 100 üzerinden :percent',

    'actions_summary' => 'Eylemler',

    'chips' => [
        'acknowledge' => 'Onayla',
        'acknowledge_aria' => ':name için anormallik uyarısını onayla',
        'snooze' => 'Ertele',
        'snooze_options' => 'Erteleme seçenekleri',
        'snooze_1w' => '1 hafta',
        'snooze_1m' => '1 ay',
        'snooze_3m' => '3 ay',
        'mark_expected' => 'Beklenen olarak işaretle',
        'mark_expected_aria' => ':name için anormallik uyarısını beklenen olarak işaretle',
        'dismiss' => 'Kapat',
        'dismiss_aria' => ':name için anormallik uyarısını kapat',
        'unknown_merchant' => 'bilinmeyen işyeri',
    ],
];
