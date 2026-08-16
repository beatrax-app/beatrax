<?php

declare(strict_types=1);

return [
    'help_paypal' => 'PayPal dışa aktarmaları bakiye satırı içermez, bu yüzden bunu elle ayarla.',
    'help_asn' => 'En son hesap ekstrene göre otomatik olarak sabitlenir. Yalnızca gerçek bakiyenin farklı olduğunu biliyorsan değiştir.',
    'help_default' => "Yalnızca güncel gerçek bakiyenin Beatrax'ın hesapladığından farklı olduğunu biliyorsan değiştir.",

    'legend' => ':name için tahmin açılış bakiyesi',
    'opening_label' => 'Açılış bakiyesi',
    'opening_placeholder' => 'ör. 1.250,00',
    'as_of_label' => 'Açılış bakiyesi şu tarih itibarıyla',
    'as_of_help' => 'Yukarıdaki rakamın geçerli olduğu tarih.',

    'divergence' => "Bu tutar, Beatrax'ın içe aktardığın işlemlerden hesapladığı bakiyeden €500 fazla sapıyor. Emin misin?",
    'use_beatrax' => "Beatrax'ın rakamını kullan",
    'use_mine' => 'Kendi rakamımı kullan',

    'save' => 'Açılış bakiyesini kaydet',
    'saved' => 'Kaydedildi.',

    'toast' => [
        'updated' => 'Açılış bakiyesi güncellendi.',
    ],

    'errors' => [
        'invalid_number' => 'Açılış bakiyesi geçerli bir sayı olmalıdır.',
        'date_required' => 'Bu açılış bakiyesinin geçerli olduğu tarihi seç.',
        'date_invalid' => 'Açılış bakiyesi tarihi geçerli bir ISO tarihi olmalıdır (YYYY-MM-DD).',
        'date_future' => 'Açılış bakiyesi tarihi gelecekte olamaz.',
    ],
];
