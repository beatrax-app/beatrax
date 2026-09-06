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
        'db_backup' => ['label' => 'Veritabanını yedekle', 'description' => 'Yedekler dizinine zaman damgalı bir SQLite kopyası yazar; veritabanı son yedekten bu yana değişmediyse hiçbir şey yazmaz. Saklanan bir kopya, saklama ilkesine göre eski yedekleri de siler.'],
        'doctor' => ['label' => 'Doctor çalıştır', 'description' => 'Operasyonel probe paketini çalıştırır ve her satır için pass / warn / fail bildirir. Warn ya da fail satırı sıfırdan farklı bir çıkış kodu verir.'],
        'failed_jobs' => ['label' => 'Başarısız işleri temizle', 'description' => "Laravel'in yönettiği failed_jobs tablosundan 30 günden eski her kaydı siler; işin yeniden denenmiş olup olmaması fark etmez."],
        'cache_clear' => ['label' => 'Önbelleği temizle', 'description' => 'Uygulamanın önbellek deposunu boşaltır.'],
        'route_list' => ['label' => 'Rotaları listele', 'description' => 'Kayıtlı her HTTP rotasını standart çıktıya yazar.'],
        'config_show' => ['label' => 'Yapılandırmayı göster', 'description' => 'Bütün bir yapılandırma dosyasını ya da içindeki noktalı bir anahtarın değerini yazar.'],
        'view_clear' => ['label' => 'Görünüm önbelleğini temizle', 'description' => 'Derlenmiş Blade görünümlerinin önbelleğini boşaltır.'],
        'queue_retry' => ['label' => 'Başarısız işleri yeniden dene', 'description' => 'Kimliğe göre tek bir başarısız işi ya da `all` verildiğinde başarısız olan tüm işleri yeniden dener.'],
        'rederive_fingerprints' => ['label' => 'Parmak izlerini yeniden türet', 'description' => 'Hâlâ geçerli normalleştirme sürümünün altında olan her işlemin parmak izini yeniden hesaplar. Buradan çalıştırıldığında sayıyı bildirir ve hiçbir şey yazmaz.'],
        'demo_seed' => ['label' => 'Örnek veri yükle', 'description' => 'Örnek bir defter ekler — hesaplar, işlemler, bütçeler, hedefler ve uyarılar — uygulamayı içinde bir şey varken görebilmen için uydurulmuş. Zaten olanın yerine geçmez, üstüne eklenir ve hiçbiri gerçek bir kişinin verisi değildir.'],
        'db_restore' => ['label' => 'Veritabanını geri yükle', 'description' => 'Geçerli veritabanını verilen yedek dosyasıyla değiştirir.'],
        'regenerate_recovery_codes' => ['label' => 'Kurtarma kodlarını yenile', 'description' => 'Bir kullanıcının tek kullanımlık 10 kurtarma kodunu yeniden üretir.'],
        'grant_dev' => ['label' => 'Geliştirici erişimi ver', 'description' => 'Verilen kullanıcı için is_developer=true yapar.'],
        // i18n-review: tr · command.install.description — İdempotent is kept as a
        // loanword. Turkish has no settled native form for it, and the sentence
        // depends on the reader recognising the property rather than the word.
        'install' => ['label' => 'Kurulumu çalıştır', 'description' => 'İdempotent ilk kurulum: veritabanı şeması, referans verileri ve tek kullanıcı hesabı. Yapılandırılmış bir kurulumda yeniden çalıştırıldığında mevcut hesabı yeniden onaylar ve parolayı değiştirmez.'],
    ],

    'arg' => [
        'action' => ['label' => 'Eylem'],
        'config' => ['label' => 'Yapılandırma anahtarı', 'help' => 'Yazdırılacak yapılandırma dosyası veya noktalı anahtar, örneğin `app` ya da `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'İş kimliği', 'help' => 'Tüm başarısız işleri yeniden denemek için `all` yaz, tek bir kaydı denemek için bir iş kimliği gir. Boş bırakılırsa hiçbir şey yeniden denenmez.', 'placeholder' => 'all (veya belirli bir kimlik)'],
        'queue' => ['label' => 'Kuyruk adı', 'help' => 'İsteğe bağlı kuyruk filtresi; varsayılan olarak tüm kuyruklar.', 'placeholder' => 'default'],
        'path' => ['label' => 'Yedek dosyasının yolu', 'help' => 'Geçerli veritabanını verilen yoldaki dosyayla değiştirir.', 'placeholder' => '/yol/dosyaya/backup.sqlite'],
        'username' => ['label' => 'Kullanıcı adı', 'placeholder' => 'alice'],
    ],
];
