<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Ayarlar',
        'heading' => 'Open banking',
        'subtitle' => 'Üçüncü taraf bir PSD2 toplayıcısı olan Enable Banking üzerinden ASN veya SNS hesabındaki işlemleri otomatik olarak çek. Varsayılan olarak kapalıdır.',
        'toggle_label' => 'Open banking özelliğini etkinleştir',
        'toggle_connected' => 'Enable Banking üzerinden :bank bankasına bağlı.',
        'toggle_off_help' => 'Varsayılan olarak kapalıdır. Tek seferlik bir onay ve rehberli kurulum gerektirir.',
        'credentials_unreadable' => 'Bu cihazda kayıtlı open banking kimlik bilgileri okunamıyor, bu yüzden Beatrax bankana ulaşamıyor.',
        'credentials_unreadable_next' => 'Bunları değiştirmek için rehberli kurulumu yeniden çalıştır. Önceden içe aktarılmış işlemler bundan etkilenmez.',
        'reconfirm_body' => 'Bağlantıyı tamamlayamadan onayının süresi doldu. Open banking kurulumunu bitirmek için yeniden onayla.',
        'reconfirm_button' => 'Bitirmek için yeniden onayla',
    ],

    'status_row' => [
        'heading' => 'Open banking',
        'manage' => 'Open banking yönetimi',
        'not_connected' => 'Bağlı banka yok. İşlemleri otomatik olarak içe aktarmak için bir banka bağla.',
        'expired' => 'Onay süresi doldu — yeniden bağlanman gerekiyor.',
        'revoked' => 'Bankan bağlantıyı sonlandırdı — yeniden bağlan.',
        'connected' => 'Enable Banking üzerinden :bank bankasına bağlı. Son senkronizasyon :when.',
        'never' => 'hiç',
    ],

    'transparency' => [
        'aggregator_label' => 'Toplayıcı',
        'bank_label' => 'Banka',
        'consent_status_label' => 'Onay durumu',
        'pill_expired' => 'Süresi doldu — yeniden bağlan',
        'pill_expiring' => 'Yakında sona eriyor',
        'pill_connected' => 'Bağlı',
        'pill_revoked' => 'Bankan sonlandırdı — yeniden bağlan',
        'whats_fetched_label' => 'Neler çekiliyor',
        'whats_fetched' => 'Kaydedilmiş işlemler + bakiyeler, son 90 gün',
        'last_successful_sync_label' => 'Son başarılı senkronizasyon',
        'never' => 'Hiç',
        'last_attempt_label' => 'Son deneme',
        'last_attempt_failed' => ':when — başarısız (:reason)',
        'reason_consent_expired' => 'onay süresi doldu',
        'reason_error' => 'hata',
        'reason_truncated' => 'erken durdu',
        'reason_nothing_imported' => 'hiçbir şey kaydedilemedi',
        'reason_consent_revoked' => 'bankan sonlandırdı',
        'disconnect_button' => 'Bağlantıyı kes',
    ],

    'consent_banner' => [
        'heading' => 'Onay süresi doldu — yeniden bağlan',
        'heading_revoked' => 'Bankan bağlantıyı sonlandırdı',
        'body' => 'Son başarılı senkronizasyonun :when. Otomatik senkronizasyonu sürdürmek için yeniden bağlan.',
        'body_revoked' => 'Bankan veya Enable Banking erişimi geri çekti, bu yüzden eşitleme durdu. Son başarılı eşitleme :when. Sürdürmek için yeniden bağlan.',
        'never' => 'hiç',
        'reconnect' => 'Yeniden bağlan',
    ],

    'sync' => [
        'review_import' => 'İçe aktarmayı incele',
        'reconnect_first' => 'Önce yeniden bağlan',
        'auto_caption' => 'Günde bir kez otomatik olarak senkronize olur.',
        'sync_now' => 'Şimdi senkronize et',

        'consent_expired' => 'Onay süresi doldu — yeniden bağlan.',
        'unavailable' => 'Enable Banking geçici olarak kullanılamıyor. Kısa süre sonra yeniden dene.',
        'new_found' => ':count yeni işlem bulundu.',
        'none' => 'Yeni işlem yok.',
        'none_importable' => 'Bankan işlemler gönderdi ama hiçbiri kaydedilemedi. Nedenini görmek için içe aktarma incelemesini aç.',
        'in_progress' => 'Bir senkronizasyon zaten sürüyor. Birazdan tekrar deneyin.',
        'truncated' => 'Bankanda tek bir eşitlemenin çekebileceğinden fazla işlem vardı, bu yüzden bu çalışma erken durdu. Hiçbir şey eşitlendi olarak kaydedilmedi — sonraki eşitleme aynı noktadan başlayacak.',
    ],

    'disconnect' => [
        'heading' => 'Open banking bağlantısı kesilsin mi?',
        'body' => "Bu işlem, kayıtlı Enable Banking kimlik bilgilerini ve onayını kaldırır. Otomatik senkronizasyon hemen durur. Beatrax'a önceden içe aktarılmış işlemler bundan etkilenmez.",
        'confirm' => 'Bağlantıyı kes',
        'cancel' => 'Bağlı kal',
    ],

    'ics' => [
        'section_label' => 'Dosyadan içe aktarma — kimlik bilgisi saklanmaz',
        'heading' => 'ICS kredi kartı ekstresi',
        'step_login' => 'Giriş yap',
        'step_download' => 'Ekstreyi indir',
        'pdf_statement' => 'PDF ekstre',
        'step_drop' => 'Aşağıya bırak',
        'drop_zone_label' => 'Hesap ekstresi dosyanı buraya bırak',
        'drop_zone_hint' => 'veya bir dosyaya göz at',
        'browse_aria' => 'ICS ekstre dosyasına göz at',
        'import_button' => 'Ekstreyi içe aktar',
        'validation' => [
            'required' => "Mijn ICS'ten indirdiğin ICS ekstresini bırak.",
            'max' => 'Bu dosya çok büyük. ICS PDF ekstreleri normalde her biri 1 MB altındadır.',
            'extensions' => 'Bu bir PDF değil. Mijn ICS yalnızca PDF ekstre dışa aktarır.',
        ],
        'could_not_read' => ':filename okunamadı. Hatanın tamamı /dev/logs içinde.',
    ],

    'warning' => [
        'heading' => 'Üçüncü bir tarafa bağlanmadan önce',
        'body' => 'Open banking özelliğini açtığında, banka giriş onayın ve ardından işlem ve bakiye verilerin doğrudan bu cihazdan Enable Banking ve bankana gönderilir. Beatrax bu verileri gören bir sunucu işletmez — ancak Enable Banking ve bankan görür. Bu, verileri hiçbir yere göndermeyen diğer tüm Beatrax içe aktarma yöntemlerinden farklıdır.',
        'acknowledge' => 'İşlem verilerimin Enable Banking ve bankamla paylaşılacağını anlıyorum.',
        'confirm' => 'Open banking özelliğini etkinleştir',
        'cancel' => 'İptal',
    ],

    'wizard' => [
        'heading' => 'Bankanı bağla',
        'intro' => 'Beatrax kendi Enable Banking uygulamanı kullanır, böylece kimlik bilgilerin asla paylaşılan bir sunucuya ulaşmaz. Bu, her banka için tek seferlik bir kurulumdur.',

        'step1_title' => 'Yerel anahtar çiftini oluştur',
        'step1_body' => 'Beatrax bu cihazda bir RSA anahtar çifti oluşturur. Özel anahtar cihazdan asla çıkmaz.',
        'generate_keypair' => 'Anahtar çifti oluştur',
        'public_key_label' => 'Genel anahtar',
        'copy_public_key' => 'Genel anahtarı kopyala',
        'copied' => 'Kopyalandı',
        'redirect_uri_label' => "Yönlendirme URI'si",
        'copy_redirect_uri' => "Yönlendirme URI'sini kopyala",

        'step2_title' => 'Uygulamayı Enable Banking içinde kaydet',
        'step2_body' => "Enable Banking geliştirici portalını aç, bir uygulama oluştur ve 1. adımdaki genel anahtar ile yönlendirme URI'sini yapıştır.",
        'open_portal' => 'Enable Banking portalını aç ↗',

        'step3_title' => 'Uygulama kimliğini yapıştır',
        'application_id_label' => 'Uygulama kimliği',
        'step3_help' => 'Bu bilgi, veritabanının dışında, kısıtlı izinlere sahip yerel bir dosyada saklanır ve bu cihazdan asla çıkmaz.',

        'step4_title' => 'Bankanı seç',
        'via_enable_banking' => 'Enable Banking üzerinden',
        'other_institution' => 'Diğer kurum',
        'institution_id_placeholder' => 'Kurum kimliği',

        'step5_title' => 'Onayı tarayıcında tamamla',
        'step5_body' => 'Bankanın giriş ve onay ekranını açmak için aşağıya tıkla. Girişi ve varsa 2 adımlı doğrulamayı tamamla, ardından Open Banking kurulumunu bitirmek için otomatik olarak buraya geri getirilirsin.',
        // i18n-review: tr · step5_body_touch — the same line for a touch
        // screen; check the verb governs this case.
        'step5_body_touch' => 'Bankanın giriş ve onay ekranını açmak için aşağıya dokun. Girişi ve varsa 2 adımlı doğrulamayı tamamla, ardından Open Banking kurulumunu bitirmek için otomatik olarak buraya geri getirilirsin.',

        'cancel' => 'İptal',
        'continue' => 'Devam →',
        'continue_to_bank' => ':bank bankasına devam →',
        'your_bank' => 'bankan',

        'errors' => [
            'save_keypair_failed' => 'Anahtar çiftin diske kaydedilemedi — secrets dizininin izinlerini kontrol edip yeniden dene.',
            'generate_failed' => 'Bu cihazda anahtar çifti oluşturulamadı — OpenSSL yapılandırmanı kontrol et.',
            'export_failed' => 'Oluşturulan anahtar çifti dışa aktarılamadı.',
            'read_public_failed' => 'Oluşturulan genel anahtar okunamadı.',
            'generate_first' => 'Devam etmeden önce bir anahtar çifti oluştur.',
            'paste_application_id' => 'Devam etmeden önce Enable Banking portalındaki uygulama kimliğini yapıştır.',
            'save_application_id_failed' => 'Uygulama kimliğin diske kaydedilemedi — secrets dizininin izinlerini kontrol edip yeniden dene.',
            'choose_bank' => 'Devam etmeden önce bir banka seç.',
        ],
    ],

    'errors' => [
        'wizard_incomplete' => 'Önce Open Banking kurulum sihirbazını tamamla.',
        'no_bank_chosen' => 'Bağlanmadan önce bir banka seç.',
        'no_consent_url' => "Enable Banking bir onay URL'si döndürmedi.",
        'unparseable_consent_url' => "Enable Banking ayrıştırılamayan bir onay URL'si döndürdü.",
        'non_public_consent_host' => 'Enable Banking herkese açık olmayan bir onay sunucusu döndürdü.',
        'unsafe_consent_url' => "Enable Banking güvenli olmayan bir onay URL'si döndürdü.",
        'no_authorization_code' => 'Enable Banking geri çağrısı hiçbir yetkilendirme kodu döndürmedi.',
        'no_session_id' => 'Enable Banking bir oturum kimliği döndürmedi.',
        'oauth_state_mismatch' => 'Bu bağlanma bağlantısının süresi dolmuş veya daha önce kullanılmış. Bankanızı bağlama işlemini yeniden başlatın.',
    ],
];
