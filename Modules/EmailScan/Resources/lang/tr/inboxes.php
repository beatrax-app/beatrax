<?php

declare(strict_types=1);

return [
    'heading' => 'Gelen kutuları',
    'intro' => "Beatrax'ın fiş araması yapabilmesi için Gmail ve Microsoft 365 gelen kutularını bağla.",
    'intro_phone' => 'Gelen kutusu taraması masaüstü uygulamasında çalışır, bu telefonda değil.',

    'phone_heading' => 'Bu telefon posta kutularını taramaz',
    'phone_body' => 'Gmail veya Microsoft 365 hesabını masaüstü uygulamasında bağla — orada bulunan fişler buraya eşitleme yoluyla ulaşır.',
    'connection_canceled' => 'Bağlantı iptal edildi.',
    'connection_failed' => 'Bağlantı tamamlanamadı.',

    'backfilling' => 'Geçmiş taranıyor',
    'backfill_progress' => ':fetched / ~:count mesaj',

    'connect_heading' => 'E-postanı bağla',
    'connect_body' => "PayPal, ICS Cards, Google Play ve diğer işyerlerinden gelen fişleri içe aktarmak için Beatrax'a bir veya daha fazla gelen kutun için salt okunur erişim ver.",
    'connect_body_phone' => 'PayPal, ICS Cards, Google Play ve diğer işyerlerinin fişlerini, salt okunur erişim verdiğin gelen kutularından masaüstü uygulaması içe aktarır. Bu telefon, o içe aktarmanın bulduklarını gösterir.',
    'connect_gmail' => 'Gmail bağla',
    'connect_microsoft' => 'Microsoft 365 bağla',
    'readonly_note' => 'Beatrax yalnızca mesajları okur. Gelen kutunda hiçbir şey göndermez, etiketlemez, taşımaz veya silmez.',

    'months' => ':count ay',
    'not_scanned_yet' => 'henüz taranmadı',
    'not_scanned_yet_phone' => 'bu telefonda taranmadı',
    'last_scanned' => 'son tarama',
    'window_prefix' => 'Aralık:',
    'edit' => 'Düzenle',

    'badge' => [
        'idle' => 'Boşta',
        'backfilling' => 'Geçmiş taranıyor',
        'scanning' => 'Taranıyor',
        'rate_limited' => 'Hız sınırı',
        'needs_reauth' => 'Yeniden yetki gerekli',
        'error' => 'Hata',
    ],

    'error_detail' => 'Son tarama tamamlanmadı. Şimdi tara seçeneğini deneyin veya bu posta kutusuna yeniden bağlanın.',
    'oauth_state_mismatch' => 'Bu bağlanma bağlantısının süresi dolmuş veya daha önce kullanılmış. Bağlantıyı baştan başlat.',
    // i18n-review: tr · oauth_client_missing — connect_gmail reads "Gmail bağla"
    // with a lowercase verb, so the button has no standalone name of its own.
    // This calls it "Bağla düğmesi"; a reader may spell the whole label out.
    'oauth_client_missing' => 'O posta sağlayıcısı için tek seferlik kurulum bu cihazda tamamlanmadı, bu yüzden bağlanmak için gereken bilgiler henüz yok. Kurulumu bitirmek için yeniden Bağla düğmesine bas.',
    'oauth_no_code' => "Posta sağlayıcın seni Beatrax'ın bitirmek için ihtiyaç duyduğu kod olmadan geri gönderdi, bu yüzden hiçbir posta kutusu bağlanmadı. Bağlantıyı baştan başlat.",
    'oauth_grant_refused' => "Posta sağlayıcın Beatrax'a verilen izni reddetti — süresi dolmuş ya da geri alınmış. Bağlantıyı baştan başlat ve izni ver.",
    'oauth_exchange_failed' => 'Posta sağlayıcın bağlantıyı tamamlamadı, bu yüzden hiçbir posta kutusu eklenmedi. Birkaç dakika sonra tekrar dene.',
    'oauth_not_saved' => 'Bağlantı bu cihaza kaydedilemedi, bu yüzden hiçbir posta kutusu eklenmedi. Tekrar dene — hata sürerse uygulama günlüğü onu neyin durdurduğunu kaydeder.',
    'oauth_no_offline_access_google' => "Google, Beatrax'ın ihtiyaç duyduğu kalıcı izni vermedi; bu posta kutusu bir saat içinde taranmayı bırakırdı. OAuth izin ekranını üretime yayımla, sonra yeniden bağlan.",
    'oauth_no_offline_access' => "Posta sağlayıcın Beatrax'ın ihtiyaç duyduğu kalıcı izni vermedi; bu posta kutusu bir saat içinde taranmayı bırakırdı. Yeniden bağlan ve sorulduğunda çevrimdışı erişime izin ver.",
    'oauth_no_offline_access_google_phone' => "Google, Beatrax'ın ihtiyaç duyduğu kalıcı izni vermedi; hiçbir posta kutusu bağlanmadı. OAuth izin ekranını üretime yayımla, sonra yeniden bağlan — taramanın kendisi masaüstü uygulamasında çalışır.",
    'oauth_no_offline_access_phone' => "Posta sağlayıcın Beatrax'ın ihtiyaç duyduğu kalıcı izni vermedi; hiçbir posta kutusu bağlanmadı. Yeniden bağlan ve sorulduğunda çevrimdışı erişime izin ver — taramanın kendisi masaüstü uygulamasında çalışır.",

    'retry_seconds' => ':nsn sonra yeniden denenecek',
    'retry_minutes' => ':ndk sonra yeniden denenecek',
    'retry_hours' => ':nsa sonra yeniden denenecek',

    'reconnect' => 'Yeniden bağlan',
    'disconnect' => 'Bağlantıyı kes',
    'disconnect_confirm' => ':email bağlantısı kesilsin mi? Bu işlem, bu posta kutusunun kayıtlı kimlik bilgilerini, tarama geçmişini ve eklediğin ya da yoksaydığın adresleri kaldırır. Beatrax\'a önceden işlenmiş fişler bundan etkilenmez. Yeniden bağlandığında tarama sıfırdan başlar.',
    'scan_now' => 'Şimdi tara',
    'scan_in_progress_title' => 'Tarama zaten sürüyor',

    'add_another' => 'Başka bir gelen kutusu ekle',
    'gmail_card_body' => "Beatrax'ın fiş araması yapabilmesi için bir Gmail hesabı bağla.",
    'microsoft_card_body' => "Beatrax'ın fiş araması yapabilmesi için bir Microsoft 365 veya Outlook.com hesabı bağla.",
    'gmail_card_body_phone' => "Gmail'i masaüstü uygulaması tarar. Onu orada bağla — bu telefon, bulduklarını gösterir.",
    'microsoft_card_body_phone' => "Microsoft 365 ve Outlook.com'u masaüstü uygulaması tarar. Onları orada bağla — bu telefon, bulduklarını gösterir.",

    'discovered_heading' => 'Keşfedilen gönderenler',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (ekstreler)',
    ],
    'discovered_body' => "Fiş gönderiyor gibi görünen ancak henüz bilinen fiş gönderenleri listende olmayan adresler. Beatrax'ın taramasını istediklerini ekle, kalanları yoksay.",
    'last_seen' => 'son görülme',
    'seen_times' => ':count kez görüldü',
    'add' => 'Ekle',
    'add_aria' => ':email adresini ekle',
    'dismiss' => 'Yoksay',
    'dismiss_aria' => ':email adresini yoksay',

    'toast' => [
        'reconnect_first' => 'Taramadan önce bu gelen kutusunu yeniden bağlayın.',
        'scan_in_progress' => 'Tarama zaten sürüyor.',
        'scan_started' => 'Tarama başladı.',
        'sender_added' => 'Gönderen eklendi.',
        'sender_dismissed' => 'Gönderen yoksayıldı.',
    ],
];
