<?php

declare(strict_types=1);

return [
    'page_title' => 'Pokladní kniha',
    'heading' => 'Pokladní kniha',
    'intro' => 'Zapisuj hotovost a další výdaje mimo banku ručně. Ruční záznamy putují do stejné knihy jako importy — kategorizují se, přiřadí se k protistraně, vstupují do detekce opakování a počítají se do tvého měsíce.',

    'direction' => 'Směr',
    'expense' => 'Výdaj',
    'income' => 'Příjem',

    'amount' => 'Částka (:symbol)',
    'date' => 'Datum',
    'counterparty' => 'Protistrana',
    'counterparty_placeholder' => 'např. Pekárna',
    'category' => 'Kategorie',
    'optional' => '(volitelné)',
    'uncategorized' => 'Bez kategorie',
    'note' => 'Poznámka',

    'add_entry' => 'Přidat záznam',
    'manual_entries' => 'Ruční záznamy',
    'no_entries' => 'Zatím žádné ruční záznamy.',
    'delete_entry' => 'Smazat záznam',
    'delete_entry_caption' => 'Smazat',
    'delete' => 'Smazat',
    'delete_confirm' => 'Smazat tuto položku?',
    'delete_keep' => 'Ponechat',

    'errors' => [
        'amount_positive' => 'Zadej částku větší než nula.',
        'amount_too_large' => 'Tato částka je příliš velká. Zkontroluj číslice.',
        'amount_unreadable' => 'Částku se nepodařilo přečíst. Zadej ji nejvýše na :decimals desetinné místo, například :example.|Částku se nepodařilo přečíst. Zadej ji nejvýše na :decimals desetinná místa, například :example.|Částku se nepodařilo přečíst. Zadej ji nejvýše na :decimals desetinných míst, například :example.',
        'amount_unreadable_whole' => 'Částku se nepodařilo přečíst. Tato měna nemá desetinná místa, zadej tedy celé číslo, například :example.',
        'invalid_date' => 'Zadej platné datum.',
        'not_recorded' => 'Záznam nebyl uložen. Zkus ho přidat znovu.',
    ],

    'toast' => [
        'added' => 'Hotovostní záznam přidán.',
        'removed' => 'Hotovostní záznam odebrán.',
        'reconciled_locked' => 'Tato transakce je odsouhlasená. Pro změny nejdřív zruš odsouhlasení.',
    ],
];
