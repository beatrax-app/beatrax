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

    'unknown_account_prefix' => 'Tanımadığımız bir hesap bulduk:',
    'unknown_iban_suffix' => 'Bu hesaba bir ad ver.',

    'ics' => [
        'name' => 'ICS kartı',
        'heading' => 'ICS kart hesabına bir ad ver.',
        'help' => 'ICS verilerini ilk kez içe aktarıyorsun. Uygulamanın her yerinde tutarlı görünmesi için bu karta bir ad ver.',
        'placeholder' => 'ör. ICS kartı',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'PayPal hesabına bir ad ver.',
        'help' => 'PayPal verilerini ilk kez içe aktarıyorsun. Uygulamanın her yerinde tutarlı görünmesi için bu cüzdana bir ad ver.',
        'placeholder' => 'ör. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Google Play hesabına bir ad ver.',
        'help' => 'Google Play makbuzunu ilk kez içe aktarıyorsun. Uygulamanın her yerinde tutarlı görünmesi için bu hesaba bir ad ver.',
        'placeholder' => 'ör. Google Play',
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

    'rows_shown' => 'Gösterilen satırlar: :shown / :total',

    'show_more' => 'Daha fazla satır göster',

    'errors' => [
        'app_locked' => 'İçe aktarmak için uygulamanın kilidini açın: kilitliyken şifreleme anahtarları kullanılamaz.',
        'archive_holds_one_message' => 'Bu dosya tek bir e-posta iletisi, posta kutusu arşivi değil; arşiv olarak okunduğunda içinde hiçbir şey bulunmaz. Biçimi E-posta iletisi yapıp yeniden yükle.',
        'email_file_is_an_archive' => 'Bu dosya bir posta kutusu arşivi: birden fazla ileti içeriyor ve tek bir ileti olarak okunursa yalnızca ilki alınır. Biçimi Posta kutusu arşivi yapıp yeniden yükle.',
        'file_stopped_short' => 'Başlık satırı eşleşti, yani biçim doğru. Okuma dosyanın sonuna gelmeden durdu. Tek bir okunamayan satır buna yol açar, bu cihaz için fazla büyük bir dosya da öyle. Daha kısa bir tarih aralığı dene.',
        'file_unreadable' => 'Bu dosya okunamadı.',
        'file_unreadable_detail' => 'Uygulama bu dosyayı okuyamadı (:code). Ayrıntıların tamamı uygulama günlüğünde; bir sorun bildirirseniz bu kodu belirtin.',
        'iban_not_in_preview' => 'Bu IBAN geçerli önizlemenin bir parçası değil.',
        'not_an_email_file' => 'Bu dosya ne bir e-posta iletisi ne de bir posta kutusu arşivi, dolayısıyla içinde fiş olarak okunacak bir şey yok. Dosyana uyan içe aktarma türünü ve biçimi seç.',
        'pdf_has_no_text_layer' => 'Bu PDF hiç metin içermiyor — bir ekstrenin taraması ya da fotoğrafı, dolayısıyla içinde okunacak bir şey yok. Ekstrenin kendisini bankandan indir ya da CSV dışa aktarımı kullan.',
        'pdf_password_protected' => 'Bu PDF parolayla korunuyor, bu yüzden hiçbir okuyucu açamaz. PDF görüntüleyicinden korumasız bir kopya kaydet ve onu içe aktar.',
        'pdf_reader_unavailable' => 'Uygulamanın bu sürümünde hiç PDF okuyucu yok, bu yüzden PDF ekstresi burada açılamaz. Bu dosyayı başka bir cihazda içe aktar ya da bankandan CSV dışa aktarımı kullan.',
        'row_belongs_to_another_statement' => 'Bu satır başka bir ekstre dosyasındaki bir işleme ait. O ekstreyi de içe aktarın — ikisi birlikte okunur.',
        'row_unreadable' => 'Bu satır okunamadı.',
        'row_unreadable_detail' => 'Uygulama bu satırı okuyamadı (:code). Ayrıntıların tamamı uygulama günlüğünde; bir sorun bildirirseniz bu kodu belirtin.',
        'unknown_account' => 'Bu satır henüz ad vermediğin bir hesaba ait.',
    ],

    'receipts' => [
        'heading' => 'Bu dosya e-posta olarak okundu',
        'saved' => 'İçinde ne varsa aşağıda listelendi ve her ileti kaydedildi.',
        'none_imported' => 'Bunların hiçbiri işleme dönüşmedi, bu yüzden defterine hiçbir şey eklenmedi.',
        'shown' => 'Gösterilen iletiler: :shown / :total',
        'no_subject' => 'Konu yok',

        'state' => [
            'read' => 'Ödeme olarak okundu — defterine eklenmesi için bu içe aktarmayı onayla.',
            'not_a_payment' => 'Ödeme değil. Bu ileti bir ödemeyi doğrulamak yerine bir şey duyuruyor.',
            'unreadable' => 'Kaydedildi. Uygulama bu göndericinin fişlerini okur, ama bu iletide tutar, satıcı ve referans bulamadı.',
            'unknown_sender' => 'Kaydedildi. Uygulama bu göndericinin fişlerini okumaz, bu yüzden iletiden hiçbir şey almadı.',
        ],
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
