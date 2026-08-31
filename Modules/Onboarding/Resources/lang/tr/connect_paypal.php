<?php

declare(strict_types=1);

return [
    'eyebrow' => 'PayPal hesabın',
    'h1' => 'PayPal hesabını bağla',

    'lede_html' => 'PayPal hareket dışa aktarmanı buraya bırak — işlem başına bir satır, bakiye özeti değil. PayPal raporlarını hesabının dilinde adlandırır; şimdilik Hollandaca çifti okuyoruz: <em lang="nl">Rapport Transactiegegevens</em>, <span lang="nl">Saldorapport</span> değil. Seninki başka bir dilde çıkıyorsa indirmeden önce PayPal’ı Hollandacaya geçir.',

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

    'drop_lead' => 'Hareket dışa aktarmanı buraya bırak',
    'browse_file' => 'veya bir dosya seç',

    'file_ready' => '· ✓ hazır',

    'skip' => 'Bu adımı atla',
    'continue' => 'Devam →',

    'errors' => [
        'required' => 'Önce PayPal hareket dışa aktarmanı kutuya bırak.',
        'max' => 'Bu dosya çok büyük. PayPal hareket dışa aktarması normalde 10 MB’ın epey altında kalır.',
        'extensions' => 'Bu dosya PayPal CSV dosyasına benzemiyor. Hareket dışa aktarmasını — işlem başına bir satır, bakiye özeti değil — CSV olarak indir.',
        'unreadable' => 'Bu dosya okunamadı. Hatanın tamamı /dev/logs içinde.',
    ],
];
