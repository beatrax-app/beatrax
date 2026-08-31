<?php

declare(strict_types=1);

return [
    'help_paypal' => 'PayPal dışa aktarmaları bakiye satırı içermez, bu yüzden bunu elle ayarla.',
    'help_default' => "Yalnızca güncel gerçek bakiyenin Beatrax'ın hesapladığından farklı olduğunu biliyorsan değiştir.",

    'legend' => ':name için tahmin açılış bakiyesi',
    'opening_label' => 'Açılış bakiyesi',
    'opening_placeholder' => 'ör. :amount',
    'as_of_label' => 'Açılış bakiyesi şu tarih itibarıyla',
    'as_of_help' => 'Yukarıdaki rakamın geçerli olduğu tarih.',

    'divergence' => "Bu tutar, Beatrax'ın içe aktardığın işlemlerden hesapladığı bakiyeden :threshold fazla sapıyor. Emin misin?",
    'computed_is' => 'Beatrax :amount hesaplıyor.',
    'use_beatrax' => "Beatrax'ın rakamını kullan",
    'use_mine' => 'Kendi rakamımı kullan',

    'save' => 'Açılış bakiyesini kaydet',
    'remove' => 'Açılış bakiyesini kaldır',
    'saved' => 'Kaydedildi.',
    'removed' => 'Kaldırıldı.',

    'toast' => [
        'updated' => 'Açılış bakiyesi güncellendi.',
        'removed' => 'Açılış bakiyesi kaldırıldı.',
    ],

    'errors' => [
        'invalid_number' => 'Açılış bakiyesi geçerli bir sayı olmalıdır.',
        'date_required' => 'Bu açılış bakiyesinin geçerli olduğu tarihi seç.',
        'date_invalid' => 'Açılış bakiyesi tarihi geçerli bir ISO tarihi olmalıdır (YYYY-MM-DD).',
        'date_future' => 'Açılış bakiyesi tarihi gelecekte olamaz.',
    ],
];
