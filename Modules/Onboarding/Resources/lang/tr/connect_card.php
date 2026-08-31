<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Kredi kartın',
    'h1' => 'Aylık ekstre PDF dosyalarını indir',
    'lede' => 'Tüm aylık PDF ekstrelerini bırak — hepsini tek bir önizlemede birleştiririz.',

    'format_group_aria' => 'ICS yalnızca PDF olarak dışa aktarır',
    'issuer_note' => 'ICS şimdilik okuyabildiğimiz tek kart sağlayıcısı ve yalnızca onun Hollandaca ekstresi. Kartın başka bir sağlayıcıdansa bu adımı atla.',
    'got_it_as' => 'Şu biçimde aldım:',
    'badge_only_format' => 'tek biçim',

    'mini' => [
        'login_label' => 'Giriş yap',
        'statements_label' => 'Ekstreleri aç',
        'months_label' => 'Ayları seç',
        'months_sub' => 'Her ay için bir PDF',
        'download_label' => 'İndir',
    ],

    'drop_lead' => 'ICS PDF dosyalarını buraya bırak',
    'browse_files' => 'veya dosya seç',
    'queue_aria' => 'Kuyruktaki PDF ekstreleri',

    'skip' => 'Bu adımı atla',
    'continue' => 'Devam →',

    'errors' => [
        'required' => 'Mijn ICS üzerinden indirdiğin aylık PDF ekstreleri bırak.',
        'min' => 'Devam etmeden önce en az bir ICS PDF ekstresi bırak.',
        'each_required' => 'Mijn ICS üzerinden indirdiğin aylık PDF ekstreyi bırak.',
        'each_max' => 'Dosyalarından biri çok büyük. ICS PDF ekstreleri normalde her biri 1 MB altındadır.',
        'each_extensions' => 'Dosyalarından biri PDF değil. Mijn ICS yalnızca PDF olarak dışa aktarır — en son aylık ekstreyi dene.',
        'file_unreadable' => ':filename okunamadı. Hatanın tamamı /dev/logs içinde.',
        'none_readable' => 'ICS PDF dosyalarının hiçbirini okuyamadık. :detail',
        'full_error_in_logs' => 'Hatanın tamamı /dev/logs içinde.',
    ],
];
