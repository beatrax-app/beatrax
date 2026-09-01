<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan runner',
    'subtitle' => 'SAFE komutları tek tıkla çalıştır; DESTRUCTIVE komutlar üçlü kilidin arkasındadır.',
    'run_a_command' => 'Komut çalıştır',
    'filter_aria' => 'Çalıştırma filtresi',
    'filter' => [
        'all' => 'Tümü',
        'running' => 'Çalışan',
        'failed' => 'Başarısız',
        'destructive' => 'Yıkıcı',
    ],
    'worker_running' => 'Kuyruk worker: ÇALIŞIYOR',
    'worker_not_running' => 'Kuyruk worker: ÇALIŞMIYOR',
    'no_runs' => 'Henüz çalıştırma yok. "Komut çalıştır" düğmesine tıkla veya komut paletini (⌘K) kullan.',
    // i18n-review: tr · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Henüz çalıştırma yok. "Komut çalıştır" düğmesine dokun veya komut paletini (⌘K) kullan.',
    'recent_runs_aria' => 'Son çalıştırmalar',
    'modal_heading' => 'SAFE komut çalıştır',
    'modal_intro' => 'Hemen çalıştırmak için SAFE seviyesinde bir komut seç. DESTRUCTIVE komutlar burada listelenmez — zaman çizelgesindeki yeniden çalıştırma seçeneğini veya ⌘K paletini kullan.',
    'args_badge' => 'args',
    'args_badge_title' => 'Argüman formu açar',

    'spawning_unavailable' => 'Artisan komutları ayrı bir süreçte çalışır ve bu platform uygulamanın süreç başlatmasına izin vermiyor. Bunları bilgisayar uygulamasından çalıştır.',

    'status' => [
        'running' => 'Çalışıyor',
        'done' => 'Bitti',
        'failed' => 'Başarısız',
        'cancelled' => 'İptal edildi',
    ],
    'cancel' => 'İptal',
    'rerun' => 'Yeniden çalıştır',
    'started' => 'Başladı :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Bilinmeyen komut: :command',
        'missing_args' => ':command çalıştırılamıyor — gereken :noun: :list',
        'invalid_args' => ':command çalıştırılamıyor — :reason',
        'arg' => 'argüman',
        'started' => ':command başlatıldı (çalıştırma :runId)',
        'run_expired' => 'Çalıştırma kaydının süresi doldu — yeniden çalıştırılamaz.',
        'reran' => ':command yeniden çalıştırıldı (çalıştırma :runId)',
        'rerun_forbidden' => 'Bu çalıştırma başka bir geliştiriciye ait.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Veritabanını yedekle', 'description' => 'Yedekler dizinine (veya verilen yola) zaman damgalı bir SQLite kopyası yazar.'],
        'doctor' => ['label' => 'Doctor çalıştır', 'description' => 'Kurulu PHP / Composer / SQLite sürümlerini bildirir ve asgari gereksinimleri doğrular.'],
        'failed_jobs' => ['label' => 'Başarısız işleri temizle', 'description' => "Laravel'in yönettiği failed_jobs tablosundaki çözülmüş kayıtları temizler."],
        'cache_clear' => ['label' => 'Önbelleği temizle', 'description' => 'Uygulamanın önbellek deposunu boşaltır.'],
        'route_list' => ['label' => 'Rotaları listele', 'description' => 'Kayıtlı her HTTP rotasını standart çıktıya yazar.'],
        'config_show' => ['label' => 'Yapılandırmayı göster', 'description' => 'Verilen yapılandırma anahtarının değerini yazar.'],
        'view_clear' => ['label' => 'Görünüm önbelleğini temizle', 'description' => 'Derlenmiş Blade görünümlerinin önbelleğini boşaltır.'],
        'queue_retry' => ['label' => 'Başarısız işleri yeniden dene', 'description' => 'Bir işi (kimliğe göre) ya da başarısız olan tüm işleri (boş kimlik) yeniden dener.'],
        'rederive_fingerprints' => ['label' => 'Parmak izlerini yeniden türet', 'description' => 'Her işlemin parmak izini geçerli normalleştirme sürümüyle yeniden hesaplar.'],
        'db_restore' => ['label' => 'Veritabanını geri yükle', 'description' => 'Geçerli veritabanını verilen yedek dosyasıyla değiştirir.'],
        'migrate_fresh' => ['label' => 'Tabloları sil ve yeniden migrasyon yap', 'description' => 'Tüm tabloları siler, ardından tüm migrasyonları yeniden çalıştırır.'],
        'reset_password' => ['label' => 'Parolayı sıfırla', 'description' => 'Bir kullanıcının parolasını etkileşimli olarak sıfırlar (etkileşimsiz kullanımı reddeder).'],
        'regenerate_recovery_codes' => ['label' => 'Kurtarma kodlarını yenile', 'description' => 'Bir kullanıcının tek kullanımlık 10 kurtarma kodunu yeniden üretir.'],
        'grant_dev' => ['label' => 'Geliştirici erişimi ver', 'description' => 'Verilen kullanıcı için is_developer=true yapar.'],
        // i18n-review: tr · command.install.description — İdempotent is kept as a
        // loanword. Turkish has no settled native form for it, and the sentence
        // depends on the reader recognising the property rather than the word.
        'install' => ['label' => 'Kurulumu çalıştır', 'description' => 'İdempotent ilk kurulum. Yapılandırılmış bir kurulumda yeniden çalıştırmak yıkıcıdır.'],
    ],

    'arg' => [
        'destination' => ['label' => 'Hedef dosya', 'help' => 'Varsayılan yedek dizinini kullanmak için boş bırak.', 'placeholder' => '/yol/dosyaya/backup.sqlite (isteğe bağlı)'],
        'action' => ['label' => 'Eylem'],
        'config' => ['label' => 'Yapılandırma anahtarı', 'help' => 'Yazdırılacak yapılandırma dosyası veya noktalı anahtar, örneğin `app` ya da `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'İş kimliği', 'help' => 'Tüm başarısız işleri yeniden denemek için boş bırak; tek bir kaydı denemek için bir kimlik gir.', 'placeholder' => 'tümü (veya belirli bir kimlik)'],
        'queue' => ['label' => 'Kuyruk adı', 'help' => 'İsteğe bağlı kuyruk filtresi; varsayılan olarak tüm kuyruklar.', 'placeholder' => 'default'],
        'from' => ['label' => 'Yedek dosyasının yolu', 'help' => 'Geçerli veritabanını verilen yoldaki dosyayla değiştirir.', 'placeholder' => '/yol/dosyaya/backup.sqlite'],
        'username' => ['label' => 'Kullanıcı adı', 'placeholder' => 'alice'],
    ],
];
