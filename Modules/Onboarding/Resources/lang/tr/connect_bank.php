<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Bankan',
    'h1' => 'Bir hesap ekstresi indir, sonra aşağıya bırak',
    'lede' => 'Bankanın verdiği biçimi seç, sonra dosyayı bırak. CAMT.053 ve MT940 biçimlerini otomatik algılarız.',

    'format_group_aria' => 'Banka ekstresi biçimi',
    'got_it_as' => 'Şu biçimde aldım:',
    'badge_recommended' => 'önerilen',

    'mini' => [
        'login_label' => 'Giriş yap',
        'login_sub' => 'Bankanın web sitesi',
        'statements_label' => 'Ekstreleri aç',
        'statements_sub' => 'Bankanın menüsünde',
        'range_label' => 'Bir dönem seç',
        'range_sub' => 'Son 90 gün',
        'download_label' => 'İndir',
    ],

    'csv_picker_aria' => 'CSV dosyanı hangi banka dışa aktardı?',
    'csv_picker_from' => 'Şuradan:',

    'drop_lead_camt053' => 'CAMT.053 dosyanı buraya bırak',
    'drop_lead_mt940' => 'MT940 dosyanı buraya bırak',
    'drop_lead_csv_layout' => ':layout CSV dosyanı buraya bırak',
    'drop_lead_pick_bank' => 'CSV dosyanı hangi bankanın dışa aktardığını seç — doğru okuyabilmek için bunu bilmemiz gerekiyor.',
    'drop_lead_default' => 'Hesap ekstresi dosyanı buraya bırak',
    'browse_file' => 'veya bir dosya seç',

    'format_help_camt053' => 'CAMT.053, XML biçiminde bir hesap ekstresidir — internet bankacılığında ekstreler ya da indirmeler altında bulunur.',
    'format_help_mt940' => 'MT940, düz metin bir ekstredir; XML ve CSV indirmelerinin yanında .sta ya da .940 olarak sunulur.',
    'format_help_csv' => 'CSV, hesap tablosu dışa aktarımıdır. Her banka sütunları farklı sıralar, bu yüzden eşleşen düzeni seç. Seninki listede yoksa bankandan CAMT.053 ya da MT940 iste.',

    'account_name_default' => 'Banka hesabı',
    'account_name_layout' => ':layout hesabı',

    'file_ready' => '· ✓ hazır',

    'skip' => 'Bu adımı atla',
    'continue' => 'Devam →',

    'errors' => [
        'file_required' => 'Önce hesap ekstresi dosyanı kutuya bırak.',
        'file_max' => 'Bu dosya çok büyük. 10 MB altında bir hesap ekstresi bırak.',
        'file_extensions' => 'Bu dosya banka ekstresine benzemiyor. CAMT.053 XML, CSV veya MT940 dosyası bırak.',
        'pick_bank' => 'Devam etmeden önce CSV dosyanı hangi bankanın dışa aktardığını seç.',
        'unreadable' => 'Bu dosya okunamadı. Hatanın tamamı /dev/logs içinde.',
    ],
];
