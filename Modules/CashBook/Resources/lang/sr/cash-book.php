<?php

declare(strict_types=1);

return [
    'page_title' => 'Blagajnički dnevnik',
    'heading' => 'Blagajnički dnevnik',
    'intro' => 'Ručno beleži gotovinu i ostale troškove izvan banke. Ručni unosi ulaze u istu glavnu knjigu kao i tvoji uvozi — kategorišu se, ulaze u prepoznavanje ponavljajućih plaćanja i računaju se u tvoj mesec.',

    'direction' => 'Smer',
    'expense' => 'Trošak',
    'income' => 'Prihod',

    'amount' => 'Iznos (€)',
    'date' => 'Datum',
    'counterparty' => 'Druga strana',
    'counterparty_placeholder' => 'npr. Pekara',
    'category' => 'Kategorija',
    'optional' => '(opciono)',
    'uncategorized' => 'Bez kategorije',
    'note' => 'Beleška',

    'add_entry' => 'Dodaj unos',
    'manual_entries' => 'Ručni unosi',
    'no_entries' => 'Još nema ručnih unosa.',
    'delete_entry' => 'Obriši unos',
    'delete' => 'Obriši',
    'delete_confirm' => 'Обрисати овај унос?',
    'delete_keep' => 'Задржи',

    'errors' => [
        'amount_positive' => 'Unesi iznos veći od nule.',
        'amount_too_large' => 'Ovaj iznos je prevelik. Proveri cifre.',
        'amount_unreadable' => 'Ovaj iznos nije bilo moguće pročitati. Unesite ga bez razdvajača hiljada i sa najviše dve decimale, na primer :example.',
        'invalid_date' => 'Unesi ispravan datum.',
    ],

    'toast' => [
        'added' => 'Gotovinski unos je dodat.',
        'removed' => 'Gotovinski unos je uklonjen.',
    ],
];
