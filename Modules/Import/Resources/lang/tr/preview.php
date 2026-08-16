<?php

declare(strict_types=1);

return [
    'page_title' => 'İçe aktarmayı önizle',
    'heading' => 'İçe aktarmayı önizle',
    'discard' => 'İçe aktarmayı at',
    'confirm' => 'İçe aktarmayı onayla',
    'subtitle' => 'Ayrıştırılan satırları gözden geçir. Sen onaylayana kadar defterine hiçbir şey kaydedilmez.',

    'expired_html' => 'Önizlemenin süresi doldu. Yeniden denemek için <a href="/imports/new" class="underline">dosyayı yeniden yükle</a>.',

    'save_name' => 'Adı kaydet',
    'account_name_label' => 'Hesap adı',
    'account_placeholder' => 'ör. Ana birikim hesabı',
    'rename_aria' => 'Bu karşı tarafı yeniden adlandır',

    'unknown_iban_prefix' => 'Tanımadığımız bir IBAN bulduk:',
    'unknown_iban_suffix' => 'Bu hesaba bir ad ver.',

    'ics' => [
        'heading' => 'ICS kart hesabına bir ad ver.',
        'help' => 'ICS verilerini ilk kez içe aktarıyorsun. Uygulamanın her yerinde tutarlı görünmesi için bu karta bir ad ver.',
        'placeholder' => 'ör. ICS kartı',
    ],

    'paypal' => [
        'heading' => 'PayPal hesabına bir ad ver.',
        'help' => 'PayPal verilerini ilk kez içe aktarıyorsun. Uygulamanın her yerinde tutarlı görünmesi için bu cüzdana bir ad ver.',
        'placeholder' => 'ör. PayPal',
    ],

    'col_date' => 'Tarih',
    'col_funding_source' => 'Finansman kaynağı',
    'col_counterparty' => 'Karşı taraf',
    'col_amount' => 'Tutar',
    'col_status' => 'Durum',

    'status' => [
        'new' => 'Yeni',
        'new_title' => 'Defterine eklenecek.',
        'duplicate' => 'Yinelenen',
        'duplicate_title' => 'Zaten içe aktarıldı — atlanacak.',
        'enriched' => 'Zenginleştirildi',
        'enriched_title' => 'Mevcut satır daha güçlü bir kaynak referansıyla güncellenecek.',
        'error' => 'Hata',
    ],

    'chain' => [
        'heading' => 'Zincirler çözümleniyor…',
        'pending' => 'Kuyruğa alındı. Zincir çözümleyici birazdan başlayacak.',
        'running' => 'Finansman zincirleri bağlanıyor ve ekstre tahsilatları ayrıştırılıyor.',
        'failed_prefix' => 'Zincir çözümleme başarısız oldu:',
        'unknown_error' => 'bilinmeyen bir hata oluştu',
        'open_horizon' => "Horizon'u aç",
        'failed_suffix' => 'yeniden denemek veya incelemek için.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'Bu IBAN geçerli önizlemenin bir parçası değil.',
    ],
];
