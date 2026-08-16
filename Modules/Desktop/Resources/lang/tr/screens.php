<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Hoş geldin',
        'heading' => "Beatrax'a hoş geldin",
        'subtitle' => 'Yalnızca cihazında çalışan finans panelin hazır. Başlamak için ilk hesabını oluştur.',
        'get_started' => 'Başla',
    ],

    'setup' => [
        'page_title' => 'Kuruluyor…',
        'pending_heading' => 'Kuruluyor…',
        'pending_body' => 'Beatrax verilerini hazırlıyor. Bu yalnızca birkaç saniye sürer.',
        'failed_body' => "Kurulum tamamlanamadı. Beatrax'ı yeniden başlat; sorun sürerse nedenini logda bulabilirsin.",
        'ready_heading' => 'Hazır',
        'ready_body' => 'Kurulum tamamlandı. Devam ediliyor…',
    ],

    'staging' => [
        'page_title' => 'Dosya alındı',
        'heading_prefix' => 'Dosya alındı: ',
        'button_label' => 'İçe aktarmayı başlat',
        'csv_subtitle' => 'Bir banka veya PayPal dışa aktarma dosyası — önizleyip onaylamak için içe aktarmayı başlat.',
        'eml_subtitle' => 'Bir e-posta fişi — ilgili işleme eklemek için içe aktarmayı başlat.',
        'empty_heading' => 'Bu dosyayı açamadık',
        'empty_body' => 'Beatrax açtığın dosyayı okuyamadı. Bunun yerine İçe aktarmalar sayfasından içe aktarmayı dene.',
        'open_imports' => 'İçe aktarmaları aç',
    ],

    'close' => [
        'title' => 'Beatrax çalışmaya devam etsin mi?',
        'body' => 'Pencereyi kapattığında Beatrax tamamen kapanabilir ya da menü çubuğunda sessizce çalışmayı sürdürüp planlanmış e-posta taramalarına devam edebilir.',
        'button_quit' => "Beatrax'tan çık",
        'button_keep_in_tray' => 'Menü çubuğunda çalışmaya devam et',
        'checkbox_remember' => 'Seçimimi hatırla',
    ],
];
