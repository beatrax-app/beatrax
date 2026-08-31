<?php

declare(strict_types=1);

return [
    'dialog_aria' => 'Vergiden indirilebilir olarak etiketle',

    'note_label' => 'Not',
    'note_optional' => '(isteğe bağlı)',
    'note_placeholder' => 'Fatura no, tarih veya başka bir referans…',

    'category_label' => 'Kategori',
    'category_listbox_aria' => 'Vergi indirimi kategorisi',
    'no_category' => 'Kategori yok',

    'new_category' => 'Yeni kategori…',
    'new_category_placeholder' => 'Yeni kategori adı…',
    'new_category_aria' => 'Yeni kategori adı',
    'add' => 'Ekle',
    'cancel' => 'İptal',
    'cancel_new_category_aria' => 'Yeni kategoriyi iptal et',

    'assign_year' => 'Şu vergi yılına ata:',

    'save' => 'Kaydet',
    'remove_tag' => 'Etiketi kaldır',

    'batch_before' => 'Şu işyerinden :count işlem daha etiketlensin mi:',
    'batch_after' => '?',
    // i18n-review: tr · batch_confirm — :name is a counterparty. This follows the
    // neighbouring batch_before and says "işyeri", while rules.chip_counterparty says
    // "karşı taraf". Which of the two this dialog wants is the open call.
    'batch_confirm' => ':name adlı işyerinden kalan tüm işlemler vergiden indirilebilir olarak etiketlensin mi? Her biri bu kategoriyi ve bu notu alır. Etiket sonradan yalnızca tek tek işlemlerden kaldırılabilir.',
    'batch_tag_all' => 'Tümünü etiketle',
    'batch_dismiss' => 'Kapat',
];
