<?php

declare(strict_types=1);

return [
    'page_title' => 'Blagajnički dnevnik',
    'heading' => 'Blagajnički dnevnik',
    'intro' => 'Ručno bilježi gotovinu i ostale troškove izvan banke. Ručni unosi ulaze u istu glavnu knjigu kao i tvoji uvozi — kategoriziraju se, povezuju se s protustrankom, ulaze u prepoznavanje ponavljajućih plaćanja i broje se u tvoj mjesec.',

    'direction' => 'Smjer',
    'expense' => 'Trošak',
    'income' => 'Prihod',

    'amount' => 'Iznos (:symbol)',
    'date' => 'Datum',
    'counterparty' => 'Protustranka',
    'counterparty_placeholder' => 'npr. Pekarnica',
    'category' => 'Kategorija',
    'optional' => '(neobavezno)',
    'uncategorized' => 'Bez kategorije',
    'note' => 'Bilješka',

    'add_entry' => 'Dodaj unos',
    'manual_entries' => 'Ručni unosi',
    'no_entries' => 'Još nema ručnih unosa.',
    'delete_entry' => 'Izbriši unos',
    'delete_entry_caption' => 'Izbriši',
    'delete' => 'Izbriši',
    'delete_confirm' => 'Izbrisati ovaj unos?',
    'delete_keep' => 'Zadrži',

    'errors' => [
        'amount_positive' => 'Unesi iznos veći od nule.',
        'amount_too_large' => 'Ovaj iznos je prevelik. Provjeri znamenke.',
        'amount_unreadable' => 'Iznos nije bilo moguće pročitati. Unesi ga s najviše :decimals decimalom, na primjer :example.|Iznos nije bilo moguće pročitati. Unesi ga s najviše :decimals decimale, na primjer :example.|Iznos nije bilo moguće pročitati. Unesi ga s najviše :decimals decimala, na primjer :example.',
        'amount_unreadable_whole' => 'Iznos nije bilo moguće pročitati. Ova valuta nema decimale, pa unesi cijeli broj, na primjer :example.',
        'invalid_date' => 'Unesi ispravan datum.',
        'not_recorded' => 'Unos nije zabilježen. Pokušaj ga dodati ponovno.',
    ],

    'toast' => [
        'added' => 'Gotovinski unos je dodan.',
        'removed' => 'Gotovinski unos je uklonjen.',
        'reconciled_locked' => 'Ova je transakcija usklađena. Poništi usklađivanje da napraviš promjene.',
    ],
];
