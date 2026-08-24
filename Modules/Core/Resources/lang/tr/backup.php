<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => "Bu telefon uygulamanın verdiği bir dosyayı kaydedemez, bu yüzden şifreli yedek masaüstü uygulamasında alınır. İkisini eşitlemek için bu cihazı eşleştirin.",
        'unavailable' => 'Şifreli yedekler masaüstü (SQLite) sürümünde kullanılabilir. Sunucu veritabanında, veritabanının kendi yedekleme araçlarını kullan.',
        'intro' => 'Veritabanının tamamının parolayla şifrelenmiş bir kopyasını indir — parola olmadan okunamadığı için harici bir diskte veya bulut depolamada güvenle saklayabilirsin (kuantuma dayanıklı XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Parola',
        'confirm_passphrase' => 'Parola onayı',
        'keep_safe' => 'Parolayı güvenli bir yerde sakla — parola olmadan yedeği kurtarmanın bir yolu yoktur.',
        'submit' => 'Şifreli yedeği indir',
        'preparing' => 'Hazırlanıyor…',
    ],

    'restore' => [
        'heading' => 'Yedekten geri yükle',

        'intro_html' => 'Mevcut veritabanının yerine şifreli bir yedek koy. Dosya, hiçbir şey değişmeden önce çözülür ve denetlenir; ayrıca geri yüklemeden önce mevcut verilerinin anlık görüntüsü kaydedilir — ancak bu işlem yine de <strong class="text-slate-700 dark:text-slate-200">her şeyin üzerine yazar</strong>, bu yüzden korumalıdır.',
        'restored' => 'Geri yüklendi. Geri yüklenen verilerini görmek için uygulamayı yeniden yükle.',
        'snapshot_saved_prefix' => 'Önceki verilerinin anlık görüntüsü şuraya kaydedildi:',
        'file_label' => 'Şifreli yedek (.enc)',
        'uploading' => 'Yükleniyor…',
        'passphrase' => 'Parola',
        'confirm_prefix' => 'Onaylamak için',
        'confirm_suffix' => 'yaz',
        'submit' => 'Geri yükle (mevcut verilerin üzerine yazar)',
        'restoring' => 'Geri yükleniyor…',
    ],

    'errors' => [
        'passphrase_min' => 'En az :min karakterlik bir parola kullan.',
        'passphrase_mismatch' => 'İki parola birbiriyle eşleşmiyor.',
        'download_sqlite_only' => 'Şifreli indirme yalnızca SQLite sürümünde kullanılabilir.',
        'create_failed' => 'Yedek oluşturulamadı: :message',
        'confirm_phrase' => 'Onaylamak için :phrase yaz — bu işlem mevcut verilerinin yerine geçer.',
        'choose_file' => 'Geri yüklemek için şifreli bir yedek dosyası (.enc) seç.',
        'enter_passphrase' => 'Yedeğin şifrelendiği parolayı gir.',
        'unreadable' => 'Yüklenen dosya okunamadı. Yeniden dene.',
    ],
];
