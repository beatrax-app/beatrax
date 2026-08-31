<?php

declare(strict_types=1);

return [
    'page_title' => 'Zincir ipuçları',
    'heading' => 'İpuçları',
    'back_to_review' => '← İnceleme kuyruğuna dön',
    'subtitle' => 'Bir eşleştiricinin karşılığı olmadan ürettiği adaylar. Bir ödeme ipucu, eksik harcamalar geldiğinde kendiliğinden kalkar; kalanlar siz burada eleyene kadar durur.',

    'empty_heading' => 'Ayıklanacak ipucu yok',
    'empty_body' => 'Bir eşleştirici otomatik çözemediği bir zinciri öne çıkardığında burada görünür.',

    'no_counterparty' => '(karşı taraf yok)',
    'unknown_account' => '(bilinmeyen hesap)',

    'dismiss' => 'Kapat',
    'dismiss_aria' => ':id numaralı ipucunu kapat',
    'dismissed' => 'İpucu kapatıldı.',

    'kind' => [
        'ics_bulk_settle' => 'Toplu iDEAL tahsilatı (tolerans dışı)',
        'funded_by_card_hint' => 'Kartla finanse edildi (ipucu)',
        'refund_of_hint' => 'İade (ipucu)',
    ],

    'evidence' => [
        'tolerance' => 'Tolerans: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'sabit pay içinde',
            'percent_2' => 'yüzdesel pay içinde',
            'exceeded' => 'payın dışında',
            'refund_after_close' => 'kapanıştan sonra iade',
        ],
        'delta_overpaid' => ':amount fazla ödendi',
        'delta_underpaid' => ':amount eksik',
        'delta_balanced' => 'Tam denkleşiyor',
        'covered' => 'Kapsanan işlemler: :count',
        'statement' => 'Kart ekstresi #:id',
        'card_last4' => ':last4 ile biten kart',
        'original_reference' => 'Özgün sipariş referansı: :reference',
    ],
];
