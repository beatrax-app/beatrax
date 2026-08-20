<?php

declare(strict_types=1);

return [
    'page_title' => 'İşlem',
    'heading' => 'İşlem',

    'counterparty' => 'Karşı taraf',
    'amount_native' => 'Tutar (orijinal para birimi)',
    'amount_settled' => 'Tutar (EUR olarak kesinleşen)',
    'effective_rate' => 'Geçerli kur',
    'ics_markup' => 'Varsa ICS ek ücretini içerir.',

    'split' => [
        'category' => 'Kategori',
        'open' => 'Kategorilere böl',
        'heading' => 'Kategorilere bölüştürme',
        'total' => 'Toplam :amount',
        'tax_per_category' => 'Vergi etiketleri aşağıda kategori bazında ayarlanır.',
        'choose_category' => 'Bir kategori seç',
        'note_label' => 'Not',
        'note_placeholder' => 'Not (isteğe bağlı)',
        'tax_deductible' => 'Vergiden indirilebilir',
        'remove_leg_aria' => 'Bu kategoriyi kaldır',
        'add_category' => '+ Kategori ekle',
        'soft_cap' => ':count / ~20 kategori — küçük tutarları gruplandırmayı düşün.',
        'remaining_zero' => 'Kalan :amount ✓',
        'remaining_to_assign' => 'Atanacak kalan: :amount',
        'over_allocated' => ':amount fazla dağıtıldı — bir kalemi azalt.',
        'save' => 'Bölüştürmeyi kaydet',
        'saving' => 'Kaydediliyor…',
        'unsplit' => 'Bölmeyi geri al',
        'remove_to_one' => 'Bunu kaldırırsan tek kategori kalır — işlem :category olur.',
        'remove_to_one_fallback' => 'bu kategori',
        'remove_category' => 'Kategoriyi kaldır',
        'keep_category' => 'Bu kategoriyi koru',
        'restore_single' => 'Tek kategori olarak geri yüklensin mi?',
        'confirm_unsplit' => 'Evet, bölmeyi geri al',
        'keep_split' => 'Bölüştürmeyi koru',
    ],

    'tax' => [
        'section_aria' => 'Vergi etiketi',
        'label' => 'Vergiden indirilebilir',
    ],

    'reclassify' => [
        'heading' => 'Yeniden sınıflandır',
        'help' => 'Algılanan türü geçersiz kılar. Bu işlem başka bir işlemle eşleştirilmişse, transfer dışı bir tür seçmek her iki taraftaki eşleştirmeyi kaldırır.',
        'choose_aria' => 'Yeni işlem türünü seç',
        'choose_option' => 'Bir tür seç…',
        'save' => 'Kaydet',
    ],

    'type_label' => [
        'expense' => 'Gider',
        'income' => 'Gelir',
        'transfer_out' => 'Giden transfer',
        'transfer_in' => 'Gelen transfer',
        'fee' => 'Ücret',
        'refund' => 'İade',
        'adjustment' => 'Düzeltme',
    ],

    'note' => [
        'heading' => 'Not',
        'help' => 'Bu işleme ait kişisel not. Yalnızca sen görebilirsin.',
        'label' => 'Not',
        'placeholder' => 'Not ekle…',
        'save' => 'Notu kaydet',
        'saved' => 'Kaydedildi',
    ],

    'reassign' => [
        'heading' => 'Karşı tarafı yeniden ata',
        'help' => 'Bu işlem için belirlenen karşı tarafı geçersiz kılar.',
        'choose_aria' => 'Karşı taraf seç',
        'choose_option' => 'Bir karşı taraf seç…',
        'submit' => 'Yeniden ata',
    ],

    'goal' => [
        'heading' => 'Birikim hedefi',
        'help' => 'Bu işlemi birikim hedeflerinden birine say.',
        'choose_aria' => 'Bir birikim hedefi seç',
        'choose_option' => 'Bir hedef seç…',
        'submit' => 'Hedefe ekle',
        'remove_aria' => ':name kaldır',
    ],

    'delete' => [
        'heading' => 'İşlemi sil',
        'help' => 'Bu işlemi kalıcı olarak kaldırır. Bu eylem geri alınamaz.',
        'button' => 'Sil',
        'confirm_prompt' => 'Emin misin?',
        'confirm' => 'Evet, sil',
        'cancel' => 'İptal',
    ],

    'chain' => [
        'view' => 'Zinciri görüntüle',
    ],

    'toast' => [
        'reconciled_locked' => 'Bu işlem mutabakatlı. Değişiklik yapmak için mutabakatı geri al.',
        'reclassified_pair_removed' => ':type olarak yeniden sınıflandırıldı — eşleştirme kaldırıldı',
        'reclassified' => ':type olarak yeniden sınıflandırıldı',
        'note_saved' => 'Not kaydedildi',
        'unreconciled' => 'Mutabakat geri alındı — bu işlemi yeniden düzenleyebilirsin.',
        'counterparty_updated' => 'Karşı taraf güncellendi',
        'goal_attributed' => 'Bu hedefe sayılıyor',
        'goal_attribution_removed' => 'Artık bu hedefe sayılmıyor',
        'split_saved' => 'Bölüştürme kaydedildi',
        'removed_one_remains' => 'Kaldırıldı — bir kategori kaldı',
        'unsplit_restored' => 'Bölme geri alındı — tek kategoriye döndürüldü',
    ],

    'errors' => [
        'totals_must_match' => 'Kaydedilemedi — kalem toplamları işlem toplamıyla tam olarak eşleşmeli.',
        'not_found' => 'İşlem bulunamadı.',
        'amount_zero' => 'Tutar €0,00 olamaz',
        'choose_category' => 'Bir kategori seç.',
        'choose_before_removing' => 'Kaldırmadan önce bir kategori seç.',
        'choose_before_unsplitting' => 'Bölmeyi geri almadan önce bir kategori seç.',
        'not_found_or_unowned' => 'İşlem bulunamadı veya kullanıcıya ait değil.',
        'reconciled_split' => 'Bu işlem mutabakatlı. Bölüştürmesini değiştirmek için mutabakatı geri al.',
        'not_splittable' => "':type' işlem türü bölüştürülemez.",
        'min_two_legs' => 'Bir bölüştürme en az 2 kalem gerektirir.',
        'legs_non_zero' => 'Kalem tutarları sıfır olamaz.',
        'legs_parent_sign' => 'Kalem tutarları ana işlemle aynı işarete sahip olmalı.',
        'leg_category_not_accessible' => 'Kalem kategorisi bulunamadı veya kullanıcı tarafından erişilebilir değil.',
        'survivor_not_accessible' => 'Kalan kategori bulunamadı veya kullanıcı tarafından erişilebilir değil.',
        'survivor_must_be_current' => 'Kalan kategori, bölüştürmenin mevcut kalem kategorilerinden biri olmalı.',
    ],
];
