<?php

declare(strict_types=1);

return [
    'page_title' => 'İçe aktarmayı önizle',
    'heading' => 'İçe aktarmayı önizle',
    'discard' => 'İçe aktarmayı at',
    'confirm' => 'İçe aktarmayı onayla',
    'subtitle' => 'Ayrıştırılan satırları gözden geçir. Sen onaylayana kadar defterine hiçbir şey kaydedilmez.',

    'already_imported' => 'Bu dosya zaten içe aktarıldı.',

    'already_imported_link' => 'İçe aktarma sonucunu görüntüle',

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
        'failed_detail' => 'ayrıntılar iş günlüğünde',
        'open_horizon' => "Horizon'u aç",
        'failed_suffix' => 'yeniden denemek veya incelemek için.',
    ],

    'errors' => [
        'app_locked' => 'İçe aktarmak için uygulamanın kilidini açın: kilitliyken şifreleme anahtarları kullanılamaz.',
        'file_unreadable' => 'Bu dosya okunamadı.',
        'iban_not_in_preview' => 'Bu IBAN geçerli önizlemenin bir parçası değil.',
        'row_unreadable' => 'Bu satır okunamadı.',
        'unknown_account' => 'Bu satır henüz ad vermediğin bir hesaba ait.',
    ],

    'failed' => [
        'heading' => 'Bu dosya okunamadı',
        'no_rows' => 'Bu dosyada işlem bulunamadı, bu yüzden içe aktarılacak bir şey yok.',
        'nothing_read' => 'Bu dosyadaki hiçbir şey işlem olarak okunamadı, bu yüzden içe aktarılacak bir şey yok.',
        'every_row' => 'Bu dosyadaki hiçbir satır okunamadı, bu yüzden içe aktarılacak bir şey yok. Her satır nedeniyle birlikte aşağıda listeleniyor.',
        'likely_cause' => 'Genellikle başlık satırı seçtiğin kaynakla eşleşmez. Yükleme ekranındaki bankayı ve biçimi kontrol et ya da hesap özetini bankandan yeniden indir.',
        'truncated_heading' => 'Bu dosyanın yalnızca bir kısmı okunabildi',
        'truncated' => 'Okuma dosyanın ortasında durdu. O noktadan sonrası okunmadı ve içe aktarılmayacak.',
        'some_rows' => 'Bazı satırlar okunamadı. Aşağıda işaretlendi ve atlanacak; onaylamak geri kalanını içe aktarır.',
        'detail_label' => 'Ayrıştırıcının bildirdiği:',
        'rows_read_label' => 'Okunan satırlar',
        'rows_skipped_label' => 'Atlanan satırlar',
    ],
];
