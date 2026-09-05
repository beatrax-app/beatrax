<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Görünüm',
        'money' => 'Para',
        'insights' => 'Analizler ve uyarılar',
        'security' => 'Güvenlik ve cihazlar',
        'data' => 'İçe aktarma ve veriler',
        'app' => 'Uygulama',
    ],

    'title' => 'Ayarlar',
    'subtitle' => 'Finanslarının uygulamada nasıl görüneceğine dair tercihler.',

    'appearance' => [
        'heading' => 'Görünüm',
        'theme' => 'Tema',
        'theme_light' => 'Açık',
        'theme_dark' => 'Koyu',
        'theme_system' => 'Sistem',
        'theme_help' => 'Sistem, işletim sisteminin açık veya koyu ayarını izler.',
    ],

    'language' => [
        'apply' => 'Uygula',
        'heading' => 'Dil',
        'label' => 'Görüntüleme dili',

        'system' => 'Sistem',
        'help' => 'Ekrandaki kelimeleri ve tutarların yazılış biçimini değiştirir. Sistem, tarayıcının veya işletim sisteminin dilini izler; varsayılan olarak İngilizce kullanılır.',
    ],

    'country' => [
        'heading' => 'Ülke',
        'label' => 'Ülken',
        'help' => 'Uygulamanın hangi ülkenin vergi kurallarını, kamu kurumlarını ve banka ücretlerini tanıyacağını belirler. Dili ve tutarların yazılış biçimini değiştirmez.',
        'choose' => 'Bir ülke seç…',
        'switch_note' => 'Ülke değiştirmek yeni kategoriler ekler — mevcut etiketler hiçbir zaman değişmez.',

        'wording_note' => 'Vergi kategorisi adları kendi dilinizde gösterilir; :country vergi beyannamesi kendi terimlerini kullanır.',

        'countries' => [
            'at' => 'Avusturya',
            'be' => 'Belçika',
            'bg' => 'Bulgaristan',
            'ca' => 'Kanada',
            'ch' => 'İsviçre',
            'cy' => 'Kıbrıs',
            'cz' => 'Çekya',
            'de' => 'Almanya',
            'dk' => 'Danimarka',
            'ee' => 'Estonya',
            'es' => 'İspanya',
            'fi' => 'Finlandiya',
            'fr' => 'Fransa',
            'gb' => 'Birleşik Krallık',
            'gr' => 'Yunanistan',
            'hr' => 'Hırvatistan',
            'hu' => 'Macaristan',
            'ie' => 'İrlanda',
            'is' => 'İzlanda',
            'it' => 'İtalya',
            'lt' => 'Litvanya',
            'lu' => 'Lüksemburg',
            'lv' => 'Letonya',
            'mt' => 'Malta',
            'nl' => 'Hollanda',
            'no' => 'Norveç',
            'pl' => 'Polonya',
            'pt' => 'Portekiz',
            'ro' => 'Romanya',
            'se' => 'İsveç',
            'si' => 'Slovenya',
            'sk' => 'Slovakya',
            'us' => 'Amerika Birleşik Devletleri',
        ],
    ],

    'currency_display' => [
        'heading' => 'Tutar gösterimi',
        'label' => 'Tutarlar için varsayılan görünüm',
        'eur_only' => 'Tahsil edilen tutar',
        'original' => 'Orijinal tutar',
        'help' => "İşlem listesi ve Panel'deki toplamlar için geçerlidir. Sayfa bazında yine de değiştirebilirsin, ama yalnızca işlem listesinden.",
    ],

    'base_currency' => [
        'heading' => 'Temel raporlama para birimi',
        'label' => 'Raporlama para birimi',
        'help' => 'Tüm toplamlar ve özetler bu para birimine dönüştürülür. Her hesap yine de kendi orijinal para birimini yanında gösterir.',
    ],

    'exchange_rates' => [
        'heading' => 'Döviz kurları',
        'fetch_online' => 'Güncel kurları çevrimiçi al',
        'online_on' => "Kurlar her gün ECB'den ya da ECB'ye ulaşılamazsa Frankfurter'dan alınır. Yalnızca para birimi çifti sorguları — kişisel veri yok.",
        'last_updated' => 'Son güncelleme: :date.',
        'online_off' => 'Bu cihazda zaten var olan kurlar kullanılmaya devam ediyor, yedek olarak da uygulamayla birlikte gelen anlık görüntü var. Bu cihazdan hiçbir veri çıkmıyor.',
        'fetch_aria' => 'Güncel döviz kurlarını çevrimiçi al',
        'refreshing' => 'Yenileniyor…',
        'next_refresh' => 'Otomatik yenileme: günde bir kez',
        'refresh_gave_up' => 'Kurlar yenilenemedi. Bu cihazdaki mevcut kurlar kullanılmaya devam ediyor.',
        'refresh_now' => 'Şimdi yenile',
    ],

    'period' => [
        'heading' => 'Dönem',
        'label' => 'Dönemin başladığı gün',
        'help' => "1 ile 28 arasında numaralandırılır. Çoğu kullanıcı bunu 1 olarak bırakır (takvim ayı). Maaşın ayın 25'inde yatıyorsa ve “ayın” senin için o gün başlıyorsa 25 kullan.",

        'move_confirm' => 'Dönem :day. günde başlarsa, tüm zarf tutarları yeniden yerleştirilir ve iki ayın tek aya katlandığı yerlerde toplanır. Günü geri almak onları yeniden ayırmaz.',
        'move_cancel' => 'İptal',
        'move_apply' => 'Uygula',
    ],

    'recurring' => [
        'heading' => 'Düzenli işlem algılama',
        'window_label' => 'Algılama penceresi (ay)',
        'window_help' => 'İşlemler düzenli kalıplar halinde kümelenirken kaç aylık geçmişin taranacağı.',
        'income_label' => 'Asgari gelir (alt birim)',
        'income_help' => 'Bu eşiğin altındaki gelirler otomatik olarak kümelenmez. Alt birim cinsinden saklanır — :minor, :example anlamına gelir. Eşiği devre dışı bırakmak için 0 yap.',
    ],

    'drift' => [
        'heading' => 'Sapma uyarıları',
        'label' => 'Varsayılan sapma uyarısı eşiği',
        'help' => 'Düzenli bir harcamanın son tutarı önceki tutardan bu yüzdeden daha fazla farklıysa uyarılar tetiklenir. Seri bazındaki ayarlar önceliklidir.',
        'options' => [
            '1' => '±%1',
            '2' => '±%2',
            '5' => '±%5 (varsayılan)',
            '10' => '±%10',
            '25' => '±%25',
            '50' => '±%50',
        ],
    ],

    'save' => 'Ayarları kaydet',
    'saved' => 'Kaydedildi.',

    'anomaly_heading' => 'Anormallik algılama',
    'notifications_heading' => 'Bildirimler',

    'forecasting' => [
        'heading' => 'Tahmin',
        'intro' => 'Beatrax, bakiyeni hesaplarının güncel durumundan ileriye doğru yansıtır. Ekstre bakiyesi bulunmayan hesaplar için (PayPal, eski CSV içe aktarmaları) açılış bakiyesini burada belirle ki tahminler bilinen bir noktadan başlasın.',
        'no_accounts' => 'Henüz hesap yok — bir tane eklemek için hesap ekstresi içe aktar.',
    ],

    'auto_import' => [
        'heading' => 'Otomatik içe aktarma',
        'label' => 'Bırakma klasöründen otomatik içe aktar',

        'active_html' => 'Bırakma klasörü etkin. Beatrax, yeni dosyalar için <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> klasörünü 5 dakikada bir tarar.',
        'inactive_html' => 'Açık olduğunda Beatrax, <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> klasörünü 5 dakikada bir <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> ve <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> dosyaları için tarar ve bunları sihirbazla aynı eşleştirme hattından geçirerek içe aktarır. İşlenen dosyalar <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> klasörüne taşınır, böylece asla iki kez içe aktarılmaz.',
        'active_phone_html' => 'Bırakma klasörü etkin. Beatrax, yeni dosyalar için <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> klasörünü arka planda tarar. Arka plan taramasının ne zaman çalışacağına telefonun karar verir, bu yüzden dakikalar da sürebilir saatler de.',
        'inactive_phone_html' => 'Açık olduğunda Beatrax, <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> klasörünü arka planda <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> ve <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> dosyaları için tarar ve bunları sihirbazla aynı eşleştirme hattından geçirerek içe aktarır. Arka plan taramasının ne zaman çalışacağına telefonun karar verir, bu yüzden dakikalar da sürebilir saatler de. İşlenen dosyalar <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> klasörüne taşınır, böylece asla iki kez içe aktarılmaz.',
    ],

    'aliases' => [
        'heading' => 'Takma adlar',
        'intro' => "Anlaşılması güç ekstre açıklamaları için Beatrax'a öğrettiğin anlaşılır adları gözden geçir ve düzenle.",
        'manage' => 'Takma adları yönet →',
    ],

    'tax_heading' => 'Vergi',
    'data_backup_heading' => 'Veriler ve yedekleme',

    'about_updates' => [
        'heading' => 'Güncellemeler hakkında',
        'body' => "Beatrax kurulduktan sonra kendini otomatik olarak günceller. İlk sürümü kurduktan sonra yeni sürümler uygulama içi bir bant aracılığıyla gelir — GitHub'a yeniden uğraman gerekmez. İleride bir güncelleme uygulanamazsa, en son yükleyiciyi sürümler sayfasından her zaman elle indirebilirsin.",
        'body_phone' => 'Burada Beatrax kendini güncellemez. Telefon uygulamasının yeni sürümleri, diğer uygulamalarında olduğu gibi App Store ya da Google Play üzerinden gelir.',
        'check_label' => 'Güncellemeleri otomatik denetle',
        'check_on' => 'Beatrax, daha yeni imzalı bir sürüm olup olmadığını sürüm akışına sorar. Sen kurmayı seçene kadar hiçbir şey indirilmez.',
        'check_off' => 'Güncelleme denetimi yapılmaz ve bu cihazdan hiçbir şey çıkmaz. Yeni sürümleri, sürümler sayfasını kendin açarak bulursun.',
        'open_releases' => 'Sürümler sayfasını aç →',
    ],

    'privacy' => [
        'heading' => 'Gizlilik politikası',
        'body' => 'Beatrax finanslarını kendi cihazlarında tutar. Politika bunun ne demek olduğunu, isteğe bağlı çevrim içi özelliklerin ne gönderdiğini ve verilerini nasıl kaldıracağını anlatır.',
        'open' => 'Gizlilik politikasını oku →',
        'url_hint' => 'Bağlantı açılmazsa şuraya git:',
    ],

    'first_run_tour' => [
        'heading' => 'İlk çalıştırma turu',
        'body' => 'Tanıtım akışını yeniden gözden geçirmek istersen kurulum sihirbazını yeniden başlat.',
        'run_again' => 'Kurulum sihirbazını yeniden çalıştır',
    ],

    'developer' => [
        'heading' => 'Geliştirici',
        'label' => 'Uygulama içi Dev Console',
        'help' => "Dev Console'u /dev adresinde gösterir. Her girişte Gelişmiş anahtarını sıfırlar.",
        'aria' => 'Geliştirici modu',
    ],

    'errors' => [
        'period_move_failed' => 'Bütçe ayı taşınamadı, bu yüzden olduğu yerde kaldı.',
        'currency_required' => 'Lütfen bir para birimi seç.',
        'window_months' => '2 ile 60 ay arasında bir değer seç.',
        'threshold' => '%1, %2, %5, %10, %25 veya %50 arasından bir eşik seç.',
        'amount' => ':zero ve üzeri bir tutar gir.',
        'period_day' => '1 ile 28 arasında bir gün seç.',
        'currency_view' => 'Mevcut seçeneklerden birini seç.',
    ],
];
