<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Bu uygulama cihazına dosya teslim edemiyor, bu yüzden şifreli yedek masaüstü uygulamasında oluşturuluyor. İkisini eşitlemek için bu cihazı eşleştirin.',
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

        'intro_html' => 'Mevcut veritabanının yerine şifreli bir yedek koy. Dosya, hiçbir şey değişmeden önce çözülür ve denetlenir; ayrıca geri yüklemeden önce mevcut verilerinin anlık görüntüsü kaydedilir — ancak bu işlem yine de <strong class="text-slate-700 dark:text-slate-200">her şeyin üzerine yazar</strong>, bu yüzden korumalıdır. Oturumun kapatılacak, çünkü girişin de veritabanında tutuluyor.',
        'restored' => 'Yedeğiniz geri yüklendi. Oluşturulduğunda geçerli olan kullanıcı adı ve parolayla oturum açın.',
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
        'upload_failed' => 'Dosyanın yüklenmesi tamamlanmadı. Bu cihaz için çok büyük olabilir — masaüstü uygulamasında geri yükleme daha büyük bir yedeği kabul eder.',
        'enter_passphrase' => 'Yedeğin şifrelendiği parolayı gir.',
        'unreadable' => 'Yüklenen dosya okunamadı. Yeniden dene.',
        'restore_wrong_passphrase' => 'Bu parola ifadesi bu yedeği açmadı ve hiçbir şey değişmedi. Yeniden yaz ve tekrar dene. Kesinlikle doğruysa dosya oluşturulduktan sonra değiştirilmiş demektir — o zaman başka bir kopyadan geri yükle.',
        'restore_not_a_backup' => 'Bu dosya şifreli bir Beatrax yedeği değil, dolayısıyla geri yüklenecek bir şey yok ve hiçbir şey değişmedi. Yedeği alırken uygulamanın yazdığı .enc dosyasını seç.',
        'restore_contents_unreadable' => 'Yedek açıldı ama içindeki veritabanı bozuk, bu yüzden geri yüklenmedi ve hiçbir şey değişmedi. Daha eski bir yedekten geri yükle.',
        'restore_could_not_read' => 'Yedek dosyası okunamadı, bu yüzden geri yükleme çalışmadı ve hiçbir şey değişmedi. Bu cihazda boş alan olduğunu kontrol et ve tekrar dene.',
        'restore_not_supported' => 'Geri yükleme, verisini tek bir dosyada tutan sürümde çalışır; bu o sürüm değil, dolayısıyla hiçbir şey değişmedi. Sunucu veritabanında o veritabanının kendi geri yükleme araçlarını kullan.',
        'restore_failed' => 'Geri yükleme çalışmadı ve hiçbir şey değişmedi. Tekrar dene — hata sürerse uygulama günlüğü onu neyin durdurduğunu kaydeder.',
    ],
];
