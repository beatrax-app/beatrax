<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Görünümleri, komutları ve eylemleri aramak için yaz. Kapatmak için Esc tuşuna bas.',
    'search_aria' => 'Görünümleri, komutları ve eylemleri aramak için yaz',
    'dialog_aria' => 'Komut paleti',
    'token_suggest_aria' => 'Token önerileri',
    'rail_view' => 'Görünüm',
    'rail_dev' => 'Dev',
    'rail_action' => 'Eylem',
    'rail_recent' => 'Son kullanılan',
    'no_recent' => 'Henüz son seçim yok.',
    'section_transactions' => 'İşlemler',
    'section_counterparties' => 'Karşı taraflar',
    'section_categories' => 'Kategoriler',
    'section_goals_recurring' => 'Hedefler ve düzenli işlemler',
    'no_name' => '(adsız)',
    // i18n-review: tr · see_all — Turkish selects one arm, so this line carries the
    // whole range. Tüm :count sonucu gör puts the numeral before the noun with no
    // plural marking, which is the rule; whether tümünü reads better is the call.
    'see_all' => 'Tüm :count sonucu gör →',
    'no_transactions' => 'Şununla eşleşen işlem yok: ":query"',
    'source_txn' => 'işlem',
    'source_counterparty' => 'karşı taraf',
    'source_category' => 'kategori',
    'results_aria' => 'Sonuçlar',
    'no_results' => 'Sonuç yok.',
    'foot_navigate' => 'gezin',
    'foot_select' => 'seç',
    'foot_close' => 'kapat',
    'close_aria' => 'Aramayı kapat',
    'close_caption' => 'Kapat',
    'foot_try' => 'Dene',
    'results' => ':count sonuç',

    'action' => [
        'run_import' => ['label' => 'İçe aktarmayı çalıştır', 'hint' => 'İçe aktarma sihirbazını aç'],
        'scan_email' => ['label' => 'Gelen kutularını aç', 'hint' => 'Bağlı posta kutuların'],
        'open_profile' => ['label' => 'Profili aç', 'hint' => 'Ayarlar — hesap ve tercihler'],
        'toggle_theme' => ['label' => 'Görünüm ayarlarını aç', 'hint' => 'Açık, koyu veya sistem'],
    ],

    'run_command' => ':command çalıştır',

    'nav' => [
        'overview' => ['label' => 'Dev genel bakış', 'hint' => 'Sistem kartları + son çalıştırmalar'],
        'artisan' => ['label' => 'Artisan runner', 'hint' => 'İzin verilen komutları çalıştır'],
        'audit' => ['label' => 'Dev denetim günlüğü', 'hint' => 'Geliştirici modunda yaptığın işlemler'],
        'logs' => ['label' => 'Log izleyici', 'hint' => 'Canlı laravel-*.log akışı'],
        'queue' => ['label' => 'Kuyruk denetleyicisi', 'hint' => 'Bekleyen / başarısız / batch'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Sistem probeları'],
        'sql' => ['label' => 'SQL paneli', 'hint' => 'Yalnızca SELECT tarayıcısı'],
        'system' => ['label' => 'Sistem anlık görüntüsü', 'hint' => 'Ortam + yollar + yapılandırma'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Gömülü kuyruk paneli'],
        'sync_health' => ['label' => 'Eşitleme durumu', 'hint' => 'Karantinaya alınan veya atlanan birleştirme işlemleri'],
    ],
];
