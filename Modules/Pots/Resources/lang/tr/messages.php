<?php

declare(strict_types=1);

return [
    'page_title' => 'Kumbaralar · Beatrax',
    'heading' => 'Kumbaralar',
    'subtitle' => 'Toplamı her zaman gerçek hesap bakiyene eşit olan sanal alt bakiyeler.',
    'add_pot' => 'Kumbara ekle',

    'pot_fallback' => 'kumbara',

    'empty' => [
        'heading' => 'Henüz kumbara yok',
        'body' => 'Gerçek bir banka transferi yapmadan paranı düzenlemek için herhangi bir hesapta sanal alt bakiyeler oluştur.',
        'cta' => 'İlk kumbaranı ekle',
        'no_accounts_cta' => 'Hesap ekstresi içe aktar',
    ],

    'common' => [
        'cancel' => 'İptal',
        'amount' => 'Tutar',
        'note_optional' => 'Not (isteğe bağlı)',
    ],

    'actions' => [
        'fund' => 'Para ekle',
        'move' => 'Taşı',
        'edit' => 'Düzenle',
        'withdraw' => 'Para çek',
        'archive' => 'Arşivle',
        'restore' => 'Geri yükle',
    ],

    'recon' => [
        'over_allocated' => 'Kumbaralar gerçek bakiyeyi :amount aşıyor — düzeltmek için yeniden dengele',
        'real_balance' => 'Gerçek bakiye:',
        'allocated' => 'Dağıtılan:',
        'unallocated' => 'Dağıtılmamış:',
    ],

    'chip' => [
        'goal' => 'Hedef:',
        'goal_name_fallback' => 'Hedef',
        'category_fallback' => 'Kategori',
    ],

    'coverage' => [
        'spent' => 'harcandı',
        'in_pot' => 'kumbarada',
    ],

    'archive_confirm' => 'Bu kumbara arşivlensin mi? :amount tutarındaki bakiye dağıtılmamışa döner.',
    'confirm_archive_aria' => ':name kumbarasının arşivlenmesini onayla',
    'more_actions_aria' => ':name için diğer eylemler',

    'history' => [
        'show' => 'Geçmişi göster ↓',
        'hide' => 'Geçmişi gizle ↑',
    ],

    'movement' => [
        'fund' => 'Para ekleme',
        'withdraw' => 'Para çekme',
        'moved_from' => ':name kumbarasından taşındı',
        'moved_to' => ':name kumbarasına taşındı',
    ],

    'archived' => [
        'toggle' => 'Arşivlenmiş kumbaralar (:count)',
        'badge' => 'Arşivlendi',
    ],

    'form' => [
        'create_title' => 'Kumbara oluştur',
        'edit_title' => 'Kumbarayı düzenle',
        'create_subtitle' => 'Bir hesabın içindeki sanal alt bakiyeye ad ver.',
        'edit_subtitle' => 'Bu kumbaranın adını ya da bağlantısını güncelle.',
        'name' => 'Ad',
        'name_placeholder' => 'ör. Tatil fonu',
        'account' => 'Hesap',
        'select_account' => 'Bir hesap seç',
        'initial_amount' => 'Başlangıç tutarı (isteğe bağlı)',
        'initial_amount_help' => 'Tutar dağıtılmamış bakiyeden düşülür. Boş oluşturmak için boş bırak.',
        'link_to' => 'Şuna bağla (isteğe bağlı)',
        'link_goal' => 'Hedef',
        'link_none' => 'Yok',
        'select_goal' => 'Bir hedef seç',
        'save_pot' => 'Kumbarayı kaydet',
        'save_changes' => 'Değişiklikleri kaydet',
    ],

    'fund' => [
        'title' => 'Kumbaraya para ekle',
        'heading' => ':name kumbarasına para ekle',
        'submit' => 'Kumbaraya para ekle',
        'note_placeholder' => 'ör. Aylık birikim',
        'available' => 'Dağıtılabilir tutar: :amount (dağıtılmamış)',
    ],

    'move' => [
        'title' => 'Parayı taşı',
        'heading' => ':name kumbarasından taşı',
        'to' => 'Şuraya taşı',
        'select_pot' => 'Bir kumbara seç',
        'no_others_short' => 'Başka kumbara yok',
        'no_others' => 'Bu hesapta başka kumbara yok',
        'submit' => 'Parayı taşı',
        'note_placeholder' => 'ör. Tatil için transfer',
    ],

    'withdraw' => [
        'heading' => ':name kumbarasından para çek',
        'note_placeholder' => 'ör. Para çekme',
    ],

    'available_in' => 'Kullanılabilir — :name: :amount',

    'errors' => [
        'enter_name' => 'Bu kumbara için bir ad gir.',
        'select_account' => 'Bu kumbara için bir hesap seç.',
        'amount_exceeds_unallocated' => 'Tutar, dağıtılmamış bakiyeyi aşıyor.',
        'amount_exceeds_unallocated_available' => 'Tutar, dağıtılmamış bakiyeyi aşıyor (:amount kullanılabilir).',
        'amount_exceeds_pot_balance' => 'Tutar, :name kumbarasındaki bakiyeyi aşıyor (:amount kullanılabilir).',
    ],

    'toast' => [
        'pot_created' => 'Kumbara oluşturuldu.',
        'pot_updated' => 'Kumbara güncellendi.',
        'pot_funded' => 'Kumbaraya para eklendi.',
        'withdrawn' => 'Kumbaradan para çekildi.',
        'funds_moved' => 'Para taşındı.',
        'pot_archived' => 'Kumbara arşivlendi.',
        'pot_restored' => 'Kumbara geri yüklendi.',
    ],
];
