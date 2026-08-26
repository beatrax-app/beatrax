<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Uyarı duyarlılığı',
    'sensitivity_help' => "Beatrax'ın bir harcamayı o işyeri veya kategori için ne kadar kolay olağan dışı saydığı, 1 ile 100 arasında. Yüksek değer daha fazlasını işaretler.",

    'min_amount_label' => 'Asgari harcama tutarı',
    'min_amount_help' => 'Bu tutarın altındaki harcamalarda anormallikleri yok sayar. Sent (:symbol) cinsinden saklanır — 1000, :example anlamına gelir.',

    'save' => 'Anormallik ayarlarını kaydet',
    'saved' => 'Kaydedildi.',

    'suppression' => [
        'summary' => 'Bastırma kuralları',
        'empty' => 'Henüz bastırma kuralı yok. Bir harcamayı beklenen olarak işaretlediğinde burada bir kural görünür.',
        'remove' => 'Kaldır',
        'remove_aria' => 'Bastırma kuralını kaldır',
        'removed_toast' => 'Kural kaldırıldı',
    ],

    'unknown_merchant' => 'Bilinmeyen işyeri',

    'detectors' => [
        'large' => 'Yüksek harcama',
        'first_time' => 'İlk kez',
        'duplicate' => 'Yinelenen',
    ],

    'errors' => [
        'sensitivity_range' => 'Duyarlılık 1 ile 100 arasında olmalıdır.',
        'min_amount_negative' => 'Asgari harcama tutarı negatif olamaz.',
    ],
];
