<?php

declare(strict_types=1);

return [
    'page_title' => 'Pokladní kniha',
    'heading' => 'Pokladní kniha',
    'intro' => 'Zapisuj hotovost a další výdaje mimo banku ručně. Ruční záznamy putují do stejné knihy jako importy — kategorizují se, vstupují do detekce opakování a počítají se do tvého měsíce.',

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
    'delete' => 'Smazat',
    'delete_confirm' => 'Smazat tuto položku?',
    'delete_keep' => 'Ponechat',

    'errors' => [
        'amount_positive' => 'Zadej částku větší než nula.',
        'amount_too_large' => 'Tato částka je příliš velká. Zkontroluj číslice.',
        'amount_unreadable' => 'Tuto částku se nepodařilo přečíst. Zadejte ji bez oddělovače tisíců a nejvýše se dvěma desetinnými místy, například :example.',
        'invalid_date' => 'Zadej platné datum.',
    ],

    'toast' => [
        'added' => 'Hotovostní záznam přidán.',
        'removed' => 'Hotovostní záznam odebrán.',
    ],
];
