<?php

declare(strict_types=1);

return [
    'page_title' => 'YNAB / Actual üzerinden içe aktar',

    'eyebrow' => 'Geçişler',
    'heading' => 'YNAB / Actual üzerinden içe aktar',
    'intro' => 'Kategori ağacını, bütçe geçmişini ve işlemlerini YNAB4, yeni YNAB veya Actual Budget üzerinden getir. Sen gözden geçirip onaylayana kadar defterine hiçbir şey yazılmaz.',
    'reconcile_context' => 'Son :product içe aktarmana göre güncellemeler denetleniyor.',

    'source_label' => 'Kaynak',
    'file_label' => 'Dosya',
    'parse_button' => 'Dışa aktarmayı ayrıştır',

    'hints' => [
        'ynab4' => "Tüm bütçeni YNAB4'ün File → Export menüsünden ZIP dosyası olarak dışa aktar.",
        'nynab' => "Bütçeni nYNAB'da File → Export Budget yoluyla dışa aktar, ardından çıkan CSV dosyalarını ZIP olarak sıkıştır.",
        'actual' => "Bütçeni Actual Budget'ın Settings → Export data bölümünden ZIP dosyası olarak dışa aktar.",
    ],

    'errors' => [
        'unrecognised' => 'Bu, okuyabileceğimiz bir YNAB4, nYNAB veya Actual dışa aktarmasına benzemiyor. Dosyayı kontrol edip yeniden dene.',
        'file_too_large' => 'Bu dosya bir geçiş dışa aktarması için fazla büyük.',
    ],
];
