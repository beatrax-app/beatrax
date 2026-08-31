<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Bütçeler',
        'subtitle' => 'Hepsini dağıt — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Önceki dönem',
        'next_aria' => 'Sonraki dönem',
    ],

    'ready' => [
        'label' => 'Dağıtıma hazır',
        'overassigned' => 'Sahip olduğundan fazlasını dağıttın — bir zarfı azalt ya da yeni gelir bekle.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Henüz hiçbir şey dağıtılmadı',
        'copy_hint' => 'Geçen ayın planını kopyala ya da dağıtmaya başlamak için aşağıdaki bir hücreye tıkla.',
        // i18n-review: tr · empty.copy_hint_touch — the same line for a
        // touch screen; check the verb governs this case.
        'copy_hint_touch' => 'Geçen ayın planını kopyala ya da dağıtmaya başlamak için aşağıdaki bir hücreye dokun.',
        'first_hint' => 'İlk ayını dağıtmaya başlamak için aşağıdaki bir hücreye tıkla.',
        // i18n-review: tr · empty.first_hint_touch — the same line for a
        // touch screen; check the verb governs this case.
        'first_hint_touch' => 'İlk ayını dağıtmaya başlamak için aşağıdaki bir hücreye dokun.',
        'copy_button' => 'Geçen ayı kopyala',
    ],

    'no_categories' => [
        'heading' => 'Henüz gider kategorisi yok',
        'body' => 'Para dağıtmaya başlamak için bir gider kategorisi ekle.',
    ],

    'table' => [
        'category' => 'Kategori',
        'assigned' => 'Dağıtılan',
        'carried_in' => 'Devreden',
        'moved' => 'Aktarılan',
        'spent' => 'Harcanan',
        'available' => 'Kullanılabilir',
        'if_overspent' => 'Aşım olursa',
        'notify_at' => 'Bildirim eşiği',
        'actions' => 'Eylemler',
    ],

    'badge' => [
        'carries_negative' => 'Eksiyi devreder',
        'unconverted_aria' => 'Kuru bulunmayan bir para birimindeki harcama burada sayılmaz — panoya bak',
        'unconverted_title' => 'Kuru bulunmayan harcama burada sayılmaz — panoya bak',
        'over_budget' => ':count bütçe aşımı',
    ],

    'row' => [
        'assigned_aria' => ':category için dağıtılan',
        'overspend_aria' => ':category bütçesi aşılırsa',
        'notify_aria' => ':category için kullanım yüzdesinde bana bildir',
        'move_money' => 'Para taşı',
        'move' => 'Taşı',
    ],

    'overspend' => [
        'reduce' => 'Gelecek ayın dağıtıma hazır tutarını azalt',
        'carry' => 'Eksiyi bu zarfta devret',
    ],

    'history' => [
        'show' => 'Geçmişi göster ↓',
        'hide' => 'Geçmişi gizle ↑',
        'moved_from' => ':category kategorisinden taşındı',
        'moved_to' => ':category kategorisine taşındı',
        'moved_unreadable' => ':category ile taşındı — Beatrax uygulamasının daha yeni bir sürümü tarafından',
        'undo' => 'Geri al',
    ],

    'phone' => [
        'spent' => 'Harcanan :amount',
        'carried_in' => 'Devreden :amount',
        'moved' => 'Aktarılan :amount',
        'available' => 'Kullanılabilir :amount',
        'notify_at' => 'Bildirim eşiği',
    ],

    'modal' => [
        'move_from' => 'Şuradan taşı: :name',
        'move_from_fallback' => 'zarf',
        'move_to' => 'Şuraya taşı',
        'no_other' => 'Başka zarf yok',
        'select' => 'Bir zarf seç',
        'amount' => 'Tutar',
        'available_in' => 'Kullanılabilir — :name: :amount',
        'note' => 'Not (isteğe bağlı)',
        'note_placeholder' => 'ör. Restoran aşımını karşılamak için',
        'cancel' => 'İptal',
        'move_funds' => 'Parayı taşı',
    ],

    'glance' => [
        'see_all' => 'Tümünü gör →',
    ],

    'notices' => [
        'invalid_amount' => 'Geçerli bir tutar gir.',
        'threshold_range' => '1 ile 200 arasında bir tam sayı gir.',
        'copied_last_month' => 'Geçen ayın planı kopyalandı.',
        'choose_envelope' => 'Parayı taşıyacağın zarfı seç.',
        'amount_positive' => 'Sıfırdan büyük bir tutar gir.',
        'move_failed' => 'Taşıma tamamlanamadı — lütfen yeniden dene.',
        'money_moved' => 'Para taşındı.',
        'move_undone' => 'Taşıma geri alındı.',
    ],

    'errors' => [
        'assigned_negative' => 'Dağıtılan tutar negatif olamaz.',
        'invalid_overspend_mode' => 'Geçersiz aşım modu.',
        'threshold_range' => 'Bildirim eşiği 1 ile 200 arasında olmalıdır.',
        'same_envelope' => 'Kaynak ve hedef zarf farklı olmalıdır.',
        'non_positive_amount' => 'Geçersiz ya da pozitif olmayan tutar.',
        'category_not_found' => 'Kategori bulunamadı ya da kullanıcı tarafından erişilemiyor.',
    ],
];
