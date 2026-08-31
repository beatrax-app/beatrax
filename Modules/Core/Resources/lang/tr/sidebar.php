<?php

declare(strict_types=1);

return [
    'primary_nav' => 'Birincil',
    'search_placeholder' => 'Ara…',
    'search_aria' => 'Arama paletini aç',

    'section_overview' => 'GENEL BAKIŞ',
    'section_recurring' => 'TAAHHÜTLER',
    'section_planning' => 'PLANLAMA',
    'section_insights' => 'ANALİZLER',
    'section_ingestion' => 'VERİ GİRİŞİ',
    'section_organise' => 'DÜZENLEME',
    'section_settings' => 'AYARLAR',

    'nav' => [
        'community' => 'Topluluk',
        'dashboard' => 'Panel',
        'transactions' => 'İşlemler',
        'forecasts' => 'Tahminler',
        'calendar' => 'Takvim',
        'recurring' => 'Düzenli işlemler',
        'counterparties' => 'Karşı taraflar',
        'triage' => 'Ayıklama',
        'chains' => 'Zincirler',
        'drift_alerts' => 'Sapma uyarıları',
        'unusual_charges' => 'Olağan dışı harcamalar',
        'notifications' => 'Bildirimler',
        'budgets' => 'Bütçeler',
        'tax' => 'Vergi',
        'goals' => 'Hedefler',
        'pots' => 'Kumbaralar',
        'reports' => 'Raporlar',
        'reconcile' => 'Mutabakat',
        'subscriptions' => 'Abonelikler',
        'imports' => 'İçe aktarmalar',
        'migrations' => "YNAB / Actual'dan içe aktar",
        'receipts' => 'Fişler',
        'cashbook' => 'Kasa defteri',
        'email' => 'E-posta',
        'categorization' => 'Kategorilendirme',
        'data_devices' => 'Veriler ve cihazlar',
        'settings' => 'Ayarlar',
    ],

    // i18n-review: tr · hint.* — these tooltips address the reader with the polite
    // imperative while the rest of the locale uses "sen"; the source and every
    // other locale's body copy are informal. Turkish UI convention pulls the
    // other way, so which register wins is a native call.
    'hint' => [
        'dashboard' => 'Son etkinliğe genel bakış',
        'transactions' => 'Tüm işlemlere göz atın',
        'forecasts' => 'Ya olursa senaryoları',
        'calendar' => 'Yaklaşan ödemeler ve öngörülen bakiye',
        'notifications' => 'Sizi bekleyen uyarılar ve mesajlar',
        'recurring' => 'Abonelikler ve sabit ödemeler',
        'subscriptions' => 'Her abonelik ve fiyat geçmişi',
        'chains' => 'Hesaplar arası finansman zincirleri',
        'unusual_charges' => 'Alışılmış düzenin dışına çıkan harcamalar',
        'drift_alerts' => 'Abonelik fiyat değişimlerinin izlenmesi',
        'budgets' => 'Kategori başına zarf bütçeleri',
        'tax' => 'İndirilebilir kayıtlar ve yıllık dışa aktarma',
        'goals' => 'Birikim hedefleri ve ilerleme',
        'pots' => 'Adlandırılmış kumbaralara ayrılan para',
        'reports' => 'Kendi raporlarınızı oluşturun ve kaydedin',
        'reconcile' => 'Bir hesap özeti bakiyesini onaylayın',
        'imports' => 'Banka hesap özetlerini yükleyin',
        'cashbook' => 'Nakit harcamaları elle kaydedin',
        'email' => 'Bağlı gelen kutuları',
        'counterparties' => 'İşlem yaptığınız herkes',
        'triage' => 'Bilinmeyen karşı tarafları belirleyin',
        'categorization' => 'Kategorisiz işlemleri gözden geçirin',
        'community' => 'Satıcılar hakkında paylaşılan bilgi',
        'data_devices' => 'Eşitleme, eşleştirme ve yedekler',
        'settings' => 'Uygulama tercihleri',
    ],

    'badge' => [
        'transactions' => ':count işlem',
        'recurring' => ':count düzenli seri',
        'counterparties' => ':count karşı taraf',
        'triage' => 'ayıklama bekleyen :count bilinmeyen karşı taraf',
        'drift' => ':count açık sapma uyarısı',
        'anomaly' => ':count açık olağan dışı harcama',
        'notifications' => ':count okunmamış bildirim',
        'budgets' => ':count bütçe',
        'tax' => 'vergiyle ilgili etiketlenmiş :count öğe',
        'subscriptions' => ':count abonelik',
        'imports' => ':count içe aktarma',
        'chains' => 'incelenmeyi bekleyen :count zincir bağlantısı',
        'forecast' => 'Önümüzdeki :days günde :count etkin açık dönemi',
        'inboxes' => 'dikkat gerektiren :count gelen kutusu öğesi',
    ],

    'dev' => [
        'heading' => 'Geliştirici',
        'open_console' => "Dev Console'u aç",
        'pulse' => 'Kuyruk :queue · Worker :worker',
        'worker_ago' => ':count s önce',
    ],

    'account' => [
        'developer_local' => 'geliştirici · yerel',
        'local' => 'yerel',
    ],

    'sign_out' => 'Çıkış yap',
];
