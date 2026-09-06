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
        'account_maintenance' => 'Hesap işletim ücreti',
        'monthly_fee' => 'Aylık ücret',
        'quarterly_fee' => 'Üç aylık ücret',
        'annual_fee' => 'Yıllık ücret',
        'card_fee' => 'Kart ücreti',
        'transaction_fee' => 'İşlem ücreti',
        'transfer_fee' => 'Havale/EFT ücreti',
        'withdrawal_fee' => 'Para çekme ücreti',
        'transaction_levy' => 'İşlem vergisi',
        'foreign_transaction_fee' => 'Döviz işlem ücreti',
        'commission' => 'Komisyon',
        'debit_interest' => 'Borç faizi',
        'overdraft' => 'Ek hesap ücreti',
        'overdraft_interest' => 'Ek hesap faizi',
        'insufficient_funds' => 'Yetersiz bakiye ücreti',
        'penalty_fee' => 'Ceza ücreti',
        'loan_arrangement_fee' => 'Kredi tahsis ücreti',
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
        'body' => '🔒 Bu bir özel kişidir. IBAN, sen gösterene kadar gizlidir ve dışa aktarmalara girmez. Kişinin adı ise işlemlerinin göründüğü her yerde görünmeye devam eder.',
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
