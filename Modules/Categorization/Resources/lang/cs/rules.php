<?php

declare(strict_types=1);

return [
    'page_title' => 'Pravidla',
    'heading' => 'Pravidla',
    'intro' => 'Předkategorizuj transakce už při importu. Pravidla platí pro každý zdroj — banku, kartu, PayPal i účtenky z e-mailu.',
    'device_local_note' => 'Pravidla zůstávají v tomto zařízení. Nesdílejí se s vašimi ostatními zařízeními.',

    'reapply' => 'Použít pravidla znovu na historii',
    'reapplying' => 'Aplikuje se…',
    'new_rule' => 'Nové pravidlo',

    'reapply_progress_lead' => 'Pravidla se znovu aplikují…',
    'reapply_progress_of' => 'z',
    'reapply_progress_trail' => 'zkontrolovaných transakcí',

    'empty_heading' => 'Zatím žádná pravidla',
    'empty_body' => 'Pravidla porovnávají transakce podle několika podmínek a automaticky mění kategorii, protistranu, poznámku i daňový štítek — při importu a pokaždé, když je znovu použiješ na svou dosavadní historii.',
    'empty_cta' => 'Vytvoř první pravidlo',

    'col_priority' => 'Priorita',
    'col_conditions' => 'Podmínky',
    'col_actions' => 'Akce',
    'col_hits' => 'Shody',
    'col_created' => 'Vytvořeno',
    'col_row_actions' => 'Akce',

    'more_conditions' => '+:count dalších',

    'delete_confirm' => 'Smazat?',
    'delete_yes' => 'Ano, smazat',
    'cancel' => 'Zrušit',
    'edit' => 'Upravit',
    'delete' => 'Smazat',
    'edit_aria' => 'Upravit pravidlo (priorita :priority)',
    'delete_aria' => 'Smazat pravidlo (priorita :priority)',

    'footer_note' => 'Pravidla a historie obchodníků fungují společně. Smazání pravidla nesmaže to, co se Beatrax naučil z dřívějších kategorizací — další import může stejnou kategorii z historie pořád sám navrhnout.',

    'chip_category' => 'Kategorie: :path',
    'chip_counterparty' => 'Protistrana: :path',
    'chip_note' => 'Poznámka',
    'chip_tax_tag' => 'Daňový štítek',

    'flash_deleted' => 'Pravidlo smazáno.',
    'flash_not_found' => 'Pravidlo nenalezeno (mohlo být smazáno na jiné kartě).',
    'flash_saved' => 'Pravidlo uloženo.',
    'flash_reapplying' => 'Pravidla se znovu aplikují na historii…',
    'summary_no_changes' => 'Žádné změny — tvoje historie už pravidlům odpovídá.',
    'summary_updated' => 'Upraveno: :fields, :transactions.',
    'summary_fields' => ':count pole|:count pole|:count polí',
    'summary_transactions' => ':count transakce|:count transakce|:count transakcí',
    'summary_reconciled_skipped' => 'Přeskočena :count odsouhlasená transakce.|Přeskočeny :count odsouhlasené transakce.|Přeskočeno :count odsouhlasených transakcí.',
];
