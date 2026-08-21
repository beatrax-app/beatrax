<?php

declare(strict_types=1);

return [
    'page_title' => 'Pravidlá',
    'heading' => 'Pravidlá',
    'intro' => 'Zaraď transakcie do kategórií už pri importe. Pravidlá platia pre každý zdroj — banku, kartu, PayPal aj e-mailové účtenky.',
    'device_local_note' => 'Pravidlá zostávajú v tomto zariadení. Nezdieľajú sa s vašimi ostatnými zariadeniami.',

    'reapply' => 'Použiť pravidlá na históriu',
    'reapplying' => 'Aplikujú sa…',
    'new_rule' => 'Nové pravidlo',

    'reapply_progress_lead' => 'Pravidlá sa znova aplikujú…',
    'reapply_progress_of' => 'z',
    'reapply_progress_trail' => 'skontrolovaných transakcií',

    'empty_heading' => 'Zatiaľ žiadne pravidlá',
    'empty_body' => 'Pravidlá porovnávajú transakcie s viacerými podmienkami a automaticky menia kategóriu, protistranu, poznámku aj daňovú značku — pri importe a vždy, keď ich znova použiješ na svoju doterajšiu históriu.',
    'empty_cta' => 'Vytvor prvé pravidlo',

    'col_priority' => 'Priorita',
    'col_conditions' => 'Podmienky',
    'col_actions' => 'Akcie',
    'col_hits' => 'Zhody',
    'col_created' => 'Vytvorené',
    'col_row_actions' => 'Akcie',

    'more_conditions' => '+:count ďalších',

    'delete_confirm' => 'Odstrániť?',
    'delete_yes' => 'Áno, odstrániť',
    'cancel' => 'Zrušiť',
    'edit' => 'Upraviť',
    'delete' => 'Odstrániť',
    'edit_aria' => 'Upraviť pravidlo (priorita :priority)',
    'delete_aria' => 'Odstrániť pravidlo (priorita :priority)',

    'footer_note' => 'Pravidlá a história obchodníkov fungujú spoločne. Odstránenie pravidla nevymaže to, čo sa Beatrax naučil z predchádzajúcich kategorizácií — pri ďalšom importe môže rovnakú kategóriu naďalej navrhovať z histórie.',

    'chip_category' => 'Kategória: :path',
    'chip_counterparty' => 'Protistrana: :path',
    'chip_note' => 'Poznámka',
    'chip_tax_tag' => 'Daňová značka',

    'flash_deleted' => 'Pravidlo odstránené.',
    'flash_not_found' => 'Pravidlo sa nenašlo (možno bolo odstránené v inej karte).',
    'flash_saved' => 'Pravidlo uložené.',
    'flash_reapplying' => 'Pravidlá sa znova aplikujú na tvoju históriu…',
    'summary_no_changes' => 'Žiadne zmeny — tvoja história už zodpovedá pravidlám.',
    'summary_updated' => 'Upravené: :fields, :transactions.',
    'summary_fields' => ':count pole|:count polia|:count polí',
    'summary_transactions' => ':count transakcia|:count transakcie|:count transakcií',
    'summary_reconciled_skipped' => 'Preskočená :count odsúhlasená transakcia.|Preskočené :count odsúhlasené transakcie.|Preskočených :count odsúhlasených transakcií.',
];
