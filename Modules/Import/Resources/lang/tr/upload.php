<?php

declare(strict_types=1);

return [
    'page_title' => 'Hesap ekstresi yükle',
    'heading' => 'Hesap ekstresi yükle',
    'migrate_prompt' => 'Başka bir bütçe uygulamasından mı geçiyorsun?',
    'migrate_link' => "YNAB veya Actual'dan içe aktar",
    'subtitle' => 'Bir banka, kart veya PayPal dışa aktarma dosyasını ya da bir e-posta fiş dosyasını buraya bırak.',
    'mime_hint' => 'Bu dosya desteklenen bir hesap ekstresi dışa aktarmasına benzemiyor. Bir banka CSV dosyası, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kart ekstresi PDF dosyası, e-posta iletisi (.eml) veya posta kutusu arşivi (.mbox) bırak.',

    'source_label' => 'Kaynak',

    'issuer_other_bank' => 'Diğer banka (N26, Revolut, ING…)',
    'issuer_email_file' => 'E-posta dosyası (.eml, .mbox)',

    'format_label' => 'Biçim',
    'file_label' => 'Dosya',
    'submit' => 'Hesap ekstresi yükle',

    'formats' => [
        'activity_download' => 'Activity Download (CSV)',
        'email_message' => 'E-posta iletisi (.eml)',
        'mailbox_archive' => 'Posta kutusu arşivi (.mbox)',
        'ing_nl' => 'ING Hollanda (CSV)',
    ],

    'errors' => [
        'file_max' => 'Bu dosya çok büyük. Seçilen biçim için boyut sınırının altında bir hesap ekstresi dışa aktarması bırak.',
        'file_extensions' => 'Bu dosya desteklenen bir hesap ekstresi dışa aktarmasına benzemiyor. Bir banka CSV dosyası, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kart ekstresi PDF dosyası, e-posta iletisi (.eml) veya posta kutusu arşivi (.mbox) bırak.',
        'issuer_format' => ':attribute değeri :source kaynağı için geçerli değil.',
        'process_failed' => 'Bu dosya işlenemedi (:class). Hatanın tamamı /dev/logs içinde.',
    ],
];
