<?php

declare(strict_types=1);

return [
    'page_title' => 'Karşı taraf',
    'fallback_account' => 'Hesap',
    'fallback_counterparty' => 'Karşı taraf',

    'edit_display_name' => 'Görünen adı düzenle',

    'hero_net_received' => 'Net alınan',
    'hero_12mo_total' => '12 aylık toplam',
    'hero_transactions' => 'İşlemler',
    'hero_first_seen' => 'İlk görülme',

    'tabs' => [
        'overview' => 'Genel bakış',
        'transactions' => 'İşlemler',
        'chains' => 'Zincirler',
        'aliases' => 'Takma adlar',
        'transfers' => 'Transferler',
        'entries' => 'Kayıtlar',
        'payments' => 'Ödemeler',
        'tax_years' => 'Vergi yılları',
    ],

    'tablist_aria' => 'Karşı taraf bölümleri',

    'tab_note_personal' => '— özel kişiler için finansman zinciri yoktur',
    'tab_note_bank' => '— banka ücreti karşı tarafı finansman zinciri oluşturmaz',
    'tab_note_government' => '— kamu karşı tarafları için finansman zinciri yoktur',

    'recent_activity' => 'Son etkinlik',
    'recurring' => 'Düzenli',
    'uncategorized' => 'Kategorisiz',
    'no_recent_transactions' => 'Bu karşı taraf için henüz kayıtlı işlem yok.',
    'see_all' => ':count işlemin tümünü gör →',

    'bank' => [
        'fees_heading' => 'Kategoriye göre banka ücretleri',
        'no_fees' => 'Bu karşı tarafta henüz kayıtlı ücret yok.',
    ],

    'government' => [
        'intro' => 'Etkinlik görülen tüm yılların yıllık dağılımı. İçinde bulunulan yıl vurgulanır.',
        'no_payments' => 'Bu karşı taraf için henüz kayıtlı ödeme yok.',
    ],

    'merchant' => [
        'categories' => 'Kategoriler',

        'categories_empty_html' => 'Henüz kategori yok — kategorisiz işlemler <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Kategorilendirme</a> bölümünde görünür.',
        'no_recurring' => 'Düzenli kalıp algılanmadı.',
        'per_month_suffix' => '/ay',
        'funding_chain' => 'Finansman zinciri',
        'no_funding_chain' => 'Henüz finansman zinciri algılanmadı. Finansman zinciri çözümlemesi için ASN + PayPal verilerinin içe aktarılması gerekir.',
        'open_chains' => 'Zincirler incelemesini aç →',
    ],

    'personal' => [
        'contact' => 'Kişi',
        'add_tag' => '+ Etiket ekle',
        'no_recurring' => 'Düzenlilik algılanmadı — özel kişilere yapılan transferler nadiren katı bir düzen izler; düzenli kira paylaşımlarında bile tarihler kayabilir.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Bu karşı taraf henüz etiketlenmedi',
        'not_labelled_body' => 'Bilinmeyenleri etiketlemek, panelin doğru aylık toplamları ve finansman zincirlerini göstermesine yardımcı olur.',
        'label_cta' => 'Bu karşı tarafı etiketle',
    ],

    'support' => [
        'contact_help' => 'İletişim ve yardım',
        'sign_in_apply' => 'Giriş yap · başvur',
        'your_rights' => 'Hakların · itiraz et',
        'cancel' => 'İptal',
        'help_support' => 'Yardım ve destek',
        'cheaper_plan' => 'Daha ucuz plan',
        'aria_gov' => 'Yardım alma',
        'aria_merchant' => 'Destek ve iptal',
        'heading_gov' => 'Yardım alma',
        'heading_merchant' => 'Destek ve iptal',
        'cancel_by_email' => 'E-posta ile iptal et',
    ],
];
