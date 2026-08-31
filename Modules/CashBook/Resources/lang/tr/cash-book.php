<?php

declare(strict_types=1);

return [
    'page_title' => 'Kasa defteri',
    'heading' => 'Kasa defteri',
    'intro' => 'Nakit ve banka dışındaki diğer harcamaları elle kaydet. Elle girilen kayıtlar içe aktarmalarınla aynı deftere akar — kategorilendirilir, bir karşı tarafla eşleştirilir, düzenli işlem olarak algılanır ve ayına dahil edilir.',

    'direction' => 'Yön',
    'expense' => 'Gider',
    'income' => 'Gelir',

    'amount' => 'Tutar (:symbol)',
    'date' => 'Tarih',
    'counterparty' => 'Karşı taraf',
    'counterparty_placeholder' => 'ör. Fırın',
    'category' => 'Kategori',
    'optional' => '(isteğe bağlı)',
    'uncategorized' => 'Kategorisiz',
    'note' => 'Not',

    'add_entry' => 'Kayıt ekle',
    'manual_entries' => 'Elle girilen kayıtlar',
    'no_entries' => 'Henüz elle girilen kayıt yok.',
    'delete_entry' => 'Kaydı sil',
    'delete_entry_caption' => 'Sil',
    'delete' => 'Sil',
    'delete_confirm' => 'Bu kayıt silinsin mi?',
    'delete_keep' => 'Sakla',

    'errors' => [
        'amount_positive' => 'Sıfırdan büyük bir tutar gir.',
        'amount_too_large' => 'Bu tutar çok büyük. Rakamları kontrol et.',
        'amount_unreadable' => 'Tutar okunamadı. En fazla :decimals ondalık basamakla gir, örneğin :example.',
        'amount_unreadable_whole' => 'Tutar okunamadı. Bu para biriminde ondalık yoktur, bu yüzden tam sayı gir, örneğin :example.',
        'invalid_date' => 'Geçerli bir tarih gir.',
        'not_recorded' => 'Kayıt oluşturulmadı. Tekrar eklemeyi dene.',
    ],

    'toast' => [
        'added' => 'Kasa kaydı eklendi.',
        'removed' => 'Kasa kaydı kaldırıldı.',
        'reconciled_locked' => 'Bu işlem mutabakatlı. Değişiklik yapmak için mutabakatı geri al.',
    ],
];
