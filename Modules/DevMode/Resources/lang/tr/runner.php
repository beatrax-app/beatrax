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
    'recent_runs_aria' => 'Son çalıştırmalar',
    'modal_heading' => 'SAFE komut çalıştır',
    'modal_intro' => 'Hemen çalıştırmak için SAFE seviyesinde bir komut seç. DESTRUCTIVE komutlar burada listelenmez — zaman çizelgesindeki yeniden çalıştırma seçeneğini veya ⌘K paletini kullan.',
    'args_badge' => 'args',
    'args_badge_title' => 'Argüman formu açar',

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
        'arg_singular' => 'argüman',
        'arg_plural' => 'argümanlar',
        'started' => ':command başlatıldı (çalıştırma :runId)',
        'run_expired' => 'Çalıştırma kaydının süresi doldu — yeniden çalıştırılamaz.',
        'reran' => ':command yeniden çalıştırıldı (çalıştırma :runId)',
    ],
];
