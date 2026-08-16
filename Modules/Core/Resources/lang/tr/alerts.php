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

        'backup_overdue' => 'En son doğrulanmış yedeğin üzerinden :hourssa geçti. <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> komutunu çalıştır veya 03:00 planlı çalışmasını bekle.',
        'wal_mode_missing' => 'SQLite WAL modunda değil (şu anda :mode). Eş zamanlı yazma işlemleri takılabilir. Yönlendirme için <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> komutunu çalıştır.',
        'synchronous_misconfigured' => 'SQLite synchronous düzeyi :level (NORMAL/1 bekleniyordu). Kalıcılık davranışı yapılandırmadan farklı olabilir. Yönlendirme için <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> komutunu çalıştır.',
        'reconnect_link' => 'Yeniden bağlan →',
    ],
];
