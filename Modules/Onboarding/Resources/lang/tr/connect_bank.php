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
    'drop_lead_asn' => 'ASN CSV dosyanı buraya bırak',
    'drop_lead_ing' => 'ING CSV dosyanı buraya bırak',
    'drop_lead_pick_bank' => 'CSV dosyanı hangi bankanın dışa aktardığını seç — doğru okuyabilmek için bunu bilmemiz gerekiyor.',
    'drop_lead_default' => 'Hesap ekstresi dosyanı buraya bırak',
    'browse_file' => 'veya bir dosya seç',

    'banks_mt940' => 'Desteklenen: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Desteklenen: ASN, ING — kullanıcılar örnek gönderdikçe yeni biçimler eklenecek.',
    'banks_default' => 'Desteklenen: ASN, ING',

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
