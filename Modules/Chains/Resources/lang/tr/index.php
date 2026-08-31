<?php

declare(strict_types=1);

return [
    'page_title' => 'Zincirler',
    'heading' => 'Zincirler',
    'review_link' => 'İnceleme kuyruğu →',
    'hints_link' => 'İpuçları →',
    'subtitle' => 'Tek bir harcamada toplanmış alışverişler. Her kart bir harcamayı ve onu besleyen ödemeleri gösterir.',

    'empty_heading' => 'Henüz zincir yok',
    'empty_body' => 'Birkaç hesap ekstresi (banka, PayPal, kart) içe aktar; çözümleyici hesaplar arası zincirleri burada otomatik olarak gösterir.',

    'no_counterparty' => '(karşı taraf yok)',
    'leg_count' => ':count ödeme',
    'legs_more' => '+ :count daha',
    'state_aria' => 'Durum: :state',

    'state' => [
        'candidate' => 'Aday',
        'confirmed' => 'Onaylandı',
        'rejected' => 'Reddedildi',
    ],

    'kind' => [
        'paypal_funding' => 'PayPal finansmanı',
        'ics_bulk_settle' => 'Toplu iDEAL tahsilatı',
        'funded_by_card_hint' => 'Kartla finanse edildi (ipucu)',
        'refund_of_hint' => 'İade (ipucu)',
    ],
];
