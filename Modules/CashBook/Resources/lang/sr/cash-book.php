<?php

declare(strict_types=1);

return [
    'page_title' => 'Blagajnički dnevnik',
    'heading' => 'Blagajnički dnevnik',
    'intro' => 'Ručno beleži gotovinu i ostale troškove izvan banke. Ručni unosi ulaze u istu glavnu knjigu kao i tvoji uvozi — kategorišu se, povezuju se s drugom stranom, ulaze u prepoznavanje ponavljajućih plaćanja i računaju se u tvoj mesec.',

    'direction' => 'Smer',
    'expense' => 'Trošak',
    'income' => 'Prihod',

    'amount' => 'Iznos (:symbol)',
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
    'delete_entry_caption' => 'Obriši',
    'delete' => 'Obriši',
    'delete_confirm' => 'Obrisati ovaj unos?',
    'delete_keep' => 'Zadrži',

    'errors' => [
        'amount_positive' => 'Unesi iznos veći od nule.',
        'amount_too_large' => 'Ovaj iznos je prevelik. Proveri cifre.',
        'amount_unreadable' => 'Iznos nije bilo moguće pročitati. Unesi ga sa najviše :decimals decimalom, na primer :example.|Iznos nije bilo moguće pročitati. Unesi ga sa najviše :decimals decimale, na primer :example.|Iznos nije bilo moguće pročitati. Unesi ga sa najviše :decimals decimala, na primer :example.',
        'amount_unreadable_whole' => 'Iznos nije bilo moguće pročitati. Ova valuta nema decimale, pa unesi ceo broj, na primer :example.',
        'invalid_date' => 'Unesi ispravan datum.',
        'not_recorded' => 'Unos nije zabeležen. Pokušaj da ga dodaš ponovo.',
    ],

    'toast' => [
        'added' => 'Gotovinski unos je dodat.',
        'removed' => 'Gotovinski unos je uklonjen.',
        'reconciled_locked' => 'Ova transakcija je usaglašena. Poništi usaglašavanje da napraviš izmene.',
    ],
];
