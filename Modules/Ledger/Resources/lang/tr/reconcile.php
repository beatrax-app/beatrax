<?php

declare(strict_types=1);

return [
    'page_title' => 'Mutabakat',
    'heading' => 'Mutabakat',
    'intro' => 'Bir hesabın ekstre bakiyesini onaylanan işlemlerinle karşılaştır. Eşleştiklerinde, o satırları yerine sabitlemek için mutabakatı tamamla.',

    'account' => 'Hesap',
    'choose_account' => 'Bir hesap seç…',
    'statement_date' => 'Ekstre tarihi',
    'statement_balance' => 'Ekstre bakiyesi (€)',
    'balance_help' => 'Mümkün olduğunda en son içe aktardığın hesap ekstresinden önceden doldurulur — borç için negatif, her iki yönde de düzenlenebilir.',

    'cleared_balance' => 'Onaylanan bakiye',
    'statement_target' => 'Ekstre hedefi',
    'difference' => 'Fark',

    'pill' => [
        'choose_account' => 'bir hesap seç',
        'enter_balance' => 'bir ekstre bakiyesi gir',
        'matched' => 'eşleşti — :amount',
        'discrepancy' => 'fark var — :amount',
    ],

    'mismatch_html' => 'Ekstre bakiyesi henüz onaylanan bakiyenle eşleşmiyor. <a href=":url" class="underline">İşlem listesinde</a> satırların onay durumunu değiştir veya fark sıfıra ulaşana kadar girdiğin bakiyeyi ayarla — bu akış asla denkleştirme kaydı oluşturmaz.',

    'check' => 'Kontrol et',
    'complete' => 'Mutabakatı tamamla',

    'errors' => [
        'choose_account' => 'Önce bir hesap seç.',
        'invalid_balance_date' => 'Geçerli bir ekstre bakiyesi ve tarihi gir.',
        'mismatch' => 'Ekstre bakiyesi henüz onaylanan bakiyeyle eşleşmiyor — fark sıfır olana kadar onaylanan satırları veya girdiğin bakiyeyi ayarla.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Bu ekstre tarihi için sabitlenecek bir şey yok.',
        'complete' => 'Mutabakat tamamlandı — :count satır sabitlendi.',
    ],
];
