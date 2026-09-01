<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Karşı taraf türü: :type',
        'merchant' => 'İşyeri',
        'personal' => 'Kişisel',
        'bank' => 'Banka',
        'government' => 'Kamu',
        'self' => 'Kendi',
        'unknown' => 'Bilinmeyen',
    ],

    'filter_chips' => [
        'aria' => 'Türe göre filtrele',
        'all' => 'Tümü',
        'merchant' => 'İşyerleri',
        'personal' => 'Kişisel',
        'bank' => 'Bankalar',
        'government' => 'Kamu',
        'self' => 'Kendi',
        'unknown' => 'Bilinmeyen',
    ],

    'default_name' => [
        'bank_fee' => 'Banka ücreti',
    ],

    'cp_card' => [
        'aria' => 'Karşı taraf: :name',
        'recent_aria' => 'Son etkinlik',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Finansman zinciri: ',
        'join' => ' ardından ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => "IBAN gizli — görmek için IBAN'ı göster düğmesine tıkla",
        // i18n-review: tr · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => "IBAN gizli — görmek için IBAN'ı göster düğmesine dokun",
        'show' => "IBAN'ı göster",
        'hide' => "IBAN'ı gizle",
    ],

    'privacy_banner' => [
        'aria' => 'Özel kişi için gizlilik bildirimi',
        'body' => '🔒 Bu bir özel kişidir. IBAN ve kişisel bilgiler varsayılan olarak gizlidir ve dışa aktarmalarda asla paylaşılmaz.',
    ],

    'self_stub' => [
        'aria' => 'Gerçek bir karşı taraf değil',
        'heading' => 'Bu aslında bir karşı taraf değil',

        'body_rest_html' => ' burada görünüyor, çünkü işlemlerinde hesaplar arasındaki finansman ayağı olarak yer alıyor. Ama bu <strong>senin kendi hesabın</strong>, işlem yaptığın biri değil.',
        'body2' => 'Bakiye, hesap ekstreleri ve tüm işlem geçmişi için hesap görünümünü aç.',
        'open_cta' => ':name hesap görünümünü aç →',
        'hide_cta' => 'Bu listeden gizle',
        'recent_legs' => 'Hesaplar arası son ayaklar',
    ],
];
