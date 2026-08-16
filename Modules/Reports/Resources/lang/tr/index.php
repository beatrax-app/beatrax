<?php

declare(strict_types=1);

return [
    'title' => 'Raporlar',
    'page_title' => 'Raporlar · Beatrax',
    'saved_report' => 'kayıtlı rapor',
    'pinned_count' => 'sabitlenmiş',
    'dismiss' => 'Kapat',

    'build_new' => 'Yeni bir rapor oluştur',
    'view_mode_aria' => 'Görünüm modu',
    'cards' => 'Kartlar',
    'list' => 'Liste',

    'empty' => [
        'heading' => 'Henüz kayıtlı rapor yok',
        'body' => 'Aşağıdan bir tane oluşturup kaydet, burada görünsün.',
        'cta' => 'İlk raporunu oluştur →',
    ],

    'pin' => [
        'pinned_aria' => 'Sabitlendi — panelden kaldır',
        'pin_aria' => 'Sabitle — panele sabitle',
        'pinned_title' => 'Sabitlendi',
        'pin_title' => 'Panele sabitle',
        'pinned_label' => 'Sabitlendi',
        'pin_label' => 'Sabitle',
    ],

    'open' => 'Aç',
    'edit' => 'Düzenle',

    'delete_confirm' => '“:name” silinsin mi?',
    'delete_report' => 'Raporu sil',
    'cancel' => 'İptal',
    'delete' => 'Sil',
    'delete_aria' => ':name raporunu sil',

    'col' => [
        'name' => 'Ad',
        'summary' => 'Özet',
        'pinned' => 'Sabitlendi',
        'actions' => 'Eylemler',
    ],

    'flash' => [
        'not_found' => 'Rapor bulunamadı (başka bir sekmede silinmiş olabilir).',
        'deleted' => 'Rapor silindi.',
    ],
    'pin_cap' => 'En fazla 3 raporu sabitleyebilirsin. Bunu eklemek için birinin sabitlemesini kaldır.',

    'summary' => [
        'metric' => [
            'spend' => 'Harcama',
            'income' => 'Gelir',
            'net' => 'Net',
            'net_worth' => 'Net varlık',
            'fallback' => 'Tutar',
        ],
        'dimension' => [
            'category' => 'kategori',
            'time_bucket' => 'zaman aralığı',
            'counterparty' => 'karşı taraf',
            'account' => 'hesap',
            'fallback' => 'kategori',
        ],
        'period' => [
            'this_month' => 'Bu ay',
            'last_3_months' => 'Son 3 ay',
            'last_6_months' => 'Son 6 ay',
            'last_12_months' => 'Son 12 ay',
            'ytd' => 'Yıl başından bugüne',
            'this_year' => 'Bu yıl',
            'custom' => 'Özel aralık',
        ],
        'with_dimension' => ':metric · :dimension bazında · :period',
        'without_dimension' => ':metric · :period',
    ],
];
