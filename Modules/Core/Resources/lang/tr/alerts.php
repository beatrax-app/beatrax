<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Sistem uyarıları',

    'actions' => [
        'install_next_launch' => 'Sonraki açılışta yükle',
        'install_next_launch_aria' => 'Sonraki açılışta yükle — #:id numaralı sistem uyarısını çözüldü olarak işaretler',
        'skip_version' => 'Bu sürümü atla',
        'release_notes' => 'Sürüm notları →',
        'update_now' => 'Şimdi güncelle',
        'update_now_aria' => 'Şimdi güncelle — #:id numaralı sistem uyarısını çözüldü olarak işaretler',
        'remind_later' => 'Daha sonra hatırlat',
        'mark_resolved' => 'Çözüldü olarak işaretle',
        'mark_resolved_aria' => 'Çözüldü olarak işaretle — #:id numaralı sistem uyarısı',
    ],

    'messages' => [
        'update_available' => 'Güncelleme mevcut — Beatrax :version hazır. Sonraki açılışta yüklenecek.',
        'update_stale' => ':current sürümünü kullanıyorsun — :latest sürümü 30 gündür mevcut. Şimdi güncelle.',
        'update_critical' => 'Kritik güncelleme mevcut — :version sürümü şunu düzeltiyor: :summary. En kısa sürede yükle.',
        'backup_corrupt_with_path' => ':timestamp tarihinde yazılan yedek bütünlük denetimini geçemedi. :path yolunu incele. Yedeklere güvenmeden önce sorunu gider.',
        'backup_corrupt_no_path' => ':timestamp tarihinde denenen yedekleme, hiçbir dosya üretilmeden durduruldu — kaynak veritabanı bütünlük denetimini geçemedi. Yedeklere güvenmeden önce sorunu gider.',
        'backup_write_failed' => ':timestamp tarihinde başlatılan yedek tamamlanmadı — veritabanı denetimlerinden geçti, ancak yedek dosyaları yazılamadı. Boş alanı ve yedek klasörünün izinlerini kontrol et.',
        'backup_restore_failed' => ':timestamp tarihinde başlatılan geri yükleme tamamlanmadı. Önceki verilerin önce :snapshot dosyasına kaydedildi.',

        'backup_overdue' => 'En son doğrulanmış yedeğin üzerinden :hourssa geçti. Beatrax bu yedeği, uygulama açıkken günde bir kez kendisi alır — elle çalıştırılacak bir şey yok. Bu kadar eski kalıyorsa, günlük çalışma sırası geldiğinde uygulama açık değildi.',
        'backup_none_found' => 'Yedek klasöründe doğrulanmış bir yedek bulunamadı. Beatrax bu yedeği, uygulama açıkken günde bir kez kendisi alır — elle çalıştırılacak bir şey yok.',
        'wal_mode_missing' => 'SQLite WAL modunda değil (şu anda :mode). Eş zamanlı yazma işlemleri takılabilir. Yönlendirme için <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code> komutunu çalıştır.',
        'synchronous_misconfigured' => 'SQLite synchronous düzeyi :level (NORMAL/1 bekleniyordu). Kalıcılık davranışı yapılandırmadan farklı olabilir. Yönlendirme için <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code> komutunu çalıştır.',
        'oauth_scrub_set_failed' => 'OAuth gizli anahtarlarının maskelenmesi çalışmıyor. Günlükler ve denetim alıntıları, bir sonraki başarılı yüklemeye kadar maskelenmemiş belirteçler içerebilir.',
        'oauth_reauth_required' => 'OAuth gizli anahtarları kullanıcı başına depolamaya taşındı. E-posta taramasını sürdürmek için Gmail ve Microsoft yetkilendirmesini yenileyin. Eski gizli anahtar dosyası, geri alma için :file olarak yeniden adlandırıldı.',
        'oauth_reconsent' => ':provider hesabınızı yeniden bağlayın',
        'auth_recovery_code_consumed' => 'Kurtarma kodu :username tarafından kullanıldı.',
        'auth_recovery_code_failed' => ':username için başarısız kurtarma kodu denemesi.',
        'auth_lock_hard_cap_reached' => 'Çok fazla başarısız PIN denemesinden sonra oturum kapatıldı.',
        'open_banking_reconsent' => 'Bankanızı yeniden bağlayın',
        'open_banking_nothing_imported' => 'Bankanız işlemler gönderdi ancak Beatrax hiçbirini kaydedemedi, bu yüzden kayıtlarınıza hiçbir şey ulaşmadı. Nedenini görmek için Open banking ayarlarını açın.',
        'auth_lock_corrupted_key' => 'PIN kodunuz bu cihazda uygulama kilidini açamıyor: kayıtlı anahtar okunamıyor. Yeni bir PIN belirlemek için hesap parolanızla oturum açın.',
        'sync_gdk_rewrap_failed' => 'Uygulama kilidi parolası değiştirildikten sonra GDK anahtarlığının yeniden sarılması başarısız oldu — anahtarlık yeniden sarılana kadar şifrelenmiş veriler kurtarılamayabilir.',
        'worker_crashed' => 'Beatrax arka plan işlemleri beklenmedik şekilde durdu. İçe aktarmalar ve e-posta taramaları duraklatıldı. Yeniden başlatmak için uygulamayı tekrar açın.',
        'auth_lock_key_material_stranded' => 'Bu hesap için bekleyen veri şifrelemesi etkin, ancak veri anahtarını artık hiçbir uygulama kilidi sargısı tutmuyor; bu nedenle her şifreli not, açıklama ve karşı taraf ayrıntısı boş görünüyor. Geri dönüşün tek yolu, anahtarı hâlâ tutan bir cihazla eşleşmektir.',
        'auth_lock_recovery_wrap_stale' => 'Hesap parolası, uygulama kilidinin kurtarma sargısı yeniden sarılmadan değiştirildi; bu nedenle o parola artık uygulama kilidini açmıyor. PIN hâlâ açıyor. PIN hâlâ biliniyorken hesap parolasını uygulama kilidi ayarlarından yeniden bağlayın; aksi hâlde unutulan bir PIN’in ardında hiçbir şey kalmaz.',
        'reconnect_link' => 'Yeniden bağlan →',
    ],
];
