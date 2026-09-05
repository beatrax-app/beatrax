<?php

declare(strict_types=1);

return [
    'about_body' => "Anlaşılması güç banka ekstresi kodlarını anlaşılır işyeri adlarıyla eşleyen ve uygulamayla birlikte gelen bir YAML dosyası. Açtığında Beatrax içe aktarma sırasında listeyi okuyabilir; bir öneri göndermek tarayıcında GitHub'ı açar.",

    'mappings' => ':count eşleşme',
    'contributors' => ':count katkıda bulunan',

    'use_shared_list' => [
        'title' => 'Paylaşılan işyeri listesini kullan',
        'help' => 'Kendin yeniden adlandırmadığın işyerleri için anlaşılır adları doldurmak üzere Beatrax bu hazır listeyi okusun.',
    ],

    'offer_to_contribute' => [
        'title' => 'Katkı sunmayı öner',
        'help' => 'Paylaşılan listeye tek tıkla öneri gönderebilmen için ayıklama satırında “Bunu tanımlamaya yardım et” düğmesini göster.',
        // i18n-review: tr · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Paylaşılan listeye tek dokunuşla öneri gönderebilmen için ayıklama satırında “Bunu tanımlamaya yardım et” düğmesini göster.',
    ],

    'update_on_updates' => [
        'title' => 'Uygulama güncellemelerinde paylaşılan listeyi güncelle',
        'help' => 'Beatrax her kendini güncellediğinde hazır listeyi yenile.',
        'help_phone' => 'App Store ya da Google Play üzerinden Beatrax’ın yeni bir sürümü her kurulduğunda hazır listeyi yenile.',
        'note' => 'Gelecek bir uygulama güncellemesiyle etkinleşir — kullandığın sürüm kenar çubuğunun en üstünde gösterilir.',
    ],
];
