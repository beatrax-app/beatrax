<?php

declare(strict_types=1);

return [
    'page_title' => 'Hesap ekstresi yükle',
    'heading' => 'Hesap ekstresi yükle',
    'migrate_prompt' => 'Başka bir bütçe uygulamasından mı geçiyorsun?',
    'migrate_link' => "YNAB veya Actual'dan içe aktar",
    'subtitle' => 'CSV, CAMT.053, MT940 veya PDF biçiminde bir ekstre ya da bir e-posta fiş dosyası buraya bırak.',
    'mime_hint' => 'Desteklenen dosyalar: banka CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kart ekstresi PDF, e-posta iletisi (.eml) veya posta kutusu arşivi (.mbox).',

    'type_label' => 'İçe aktarma türü',

    'types' => [
        'csv' => 'CSV dosyası',
        'camt053' => 'CAMT.053 ekstresi (XML)',
        'mt940' => 'MT940 ekstresi',
        'pdf' => 'Kart ekstresi (PDF)',
        'email' => 'E-posta fiş dosyası',
    ],

    'format_label' => 'Biçim',

    'format_from_file' => 'Biçim, seçtiğin dosyaya uyması için :format olarak ayarlandı. Doğru değilse değiştir.',
    'file_label' => 'Dosya',
    'submit' => 'Hesap ekstresi yükle',

    'formats' => [
        'activity_download' => 'Activity Download (CSV)',
        'email_message' => 'E-posta iletisi (.eml)',
        'mailbox_archive' => 'Posta kutusu arşivi (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Bu dosya çok büyük. Seçilen biçim için boyut sınırının altında bir hesap ekstresi dışa aktarması bırak.',
        'file_extensions' => 'Bu dosya desteklenen bir hesap ekstresi dışa aktarmasına benzemiyor. Bir banka CSV dosyası, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kart ekstresi PDF dosyası, e-posta iletisi (.eml) veya posta kutusu arşivi (.mbox) bırak.',
        'type_format' => ':attribute değeri :type içe aktarma türü için geçerli değil.',
        'process_failed' => 'Bu dosya işlenemedi (:class). Hatanın tamamı /dev/logs içinde.',
    ],
];
