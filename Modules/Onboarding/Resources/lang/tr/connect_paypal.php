<?php

declare(strict_types=1);

return [
    'eyebrow' => 'PayPal hesabın',
    'h1' => 'PayPal hesabını bağla',

    'lede_html' => 'PayPal işlem ayrıntıları dışa aktarmanı buraya bırak — Hollanda PayPal hesaplarında <em lang="nl">Rapport Transactiegegevens</em> olarak listelenir. Bakiye raporu (<span lang="nl">Saldorapport</span>) işe yaramaz — olay bazında veriye ihtiyacımız var.',

    'format_group_aria' => 'PayPal yalnızca CSV olarak dışa aktarır',
    'got_it_as' => 'Şu biçimde aldım:',
    'badge_only_format' => 'tek biçim',

    'mini' => [
        'login_label' => 'Giriş yap',
        'custom_label' => 'Özel ekstreler',
        'range_label' => 'Bir dönem seç',
        'range_sub' => 'Son 12 ay',
        'download_label' => 'CSV olarak indir',
    ],

    'drop_lead' => 'İşlem ayrıntıları CSV dosyanı buraya bırak',
    'browse_file' => 'veya bir dosya seç',

    'file_ready' => '· ✓ hazır',

    'skip' => 'Bu adımı atla',
    'continue' => 'Devam →',

    'errors' => [
        'required' => 'Önce PayPal Rapport Transactiegegevens CSV dosyanı kutuya bırak.',
        'max' => 'Bu dosya çok büyük. PayPal Rapport Transactiegegevens dışa aktarmaları normalde 10 MB altında kalır.',
        'extensions' => 'Bu dosya PayPal CSV dosyasına benzemiyor. PayPal üzerinden Rapport Transactiegegevens raporunu (Saldorapport bakiye raporunu değil) CSV olarak indir.',
        'unreadable' => 'Bu dosya okunamadı. Hatanın tamamı /dev/logs içinde.',
    ],
];
