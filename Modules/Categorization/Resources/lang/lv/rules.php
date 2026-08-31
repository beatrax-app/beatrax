<?php

declare(strict_types=1);

return [
    'page_title' => 'Noteikumi',
    'heading' => 'Noteikumi',
    'intro' => 'Kategorizē darījumus jau importa laikā. Noteikumi attiecas uz visiem avotiem — banku, karti, PayPal un e-pasta čekiem.',
    'device_local_note' => 'Noteikumi paliek šajā ierīcē. Tie netiek koplietoti ar citām tavām ierīcēm.',

    'reapply' => 'Piemērot noteikumus vēsturei',
    'reapply_confirm' => 'Vai piemērot visus noteikumus visai jūsu vēsturei? Katra kategorija, darījuma partneris, piezīme un nodokļu atzīme, ko ievietojis noteikums, tiek pārrakstīta. Tas, ko iestatījāt ar roku, paliek, tāpat kā viss, kas ir saskaņotā konta izrakstā. Vecās vērtības neatgriež nekas.',
    'reapplying' => 'Piemēro…',
    'new_rule' => 'Jauns noteikums',

    // i18n-review: lv · reapply_progress — pārbaudīts/pārbaudīti follows the arm :count
    // selects, yet it predicates on :checked, and Latvian arm 1 also covers 21, where
    // :checked can exceed one. A native reader decides whether a fixed plural, or a
    // colon-label shape, is the better answer.
    'reapply_progress' => 'Piemēro noteikumus… :checked no :count darījumiem pārbaudīti|Piemēro noteikumus… :checked no :count darījuma pārbaudīts|Piemēro noteikumus… :checked no :count darījumiem pārbaudīti',

    'empty_heading' => 'Vēl nav neviena noteikuma',
    'empty_body' => 'Noteikumi atlasa darījumus pēc vairākiem nosacījumiem un automātiski maina kategoriju, darījuma partneri, piezīmi un nodokļu atzīmi — importa laikā un ikreiz, kad tos piemēro esošajai vēsturei.',
    'empty_cta' => 'Izveidojiet pirmo noteikumu',

    'col_priority' => 'Prioritāte',
    'col_conditions' => 'Nosacījumi',
    'col_actions' => 'Darbības',
    'col_hits' => 'Sakritības',
    'col_created' => 'Izveidots',
    'col_row_actions' => 'Darbības',
    'inactive_badge' => 'Izslēgts',
    'combinator_all' => 'VISI',
    'combinator_any' => 'JEBKURŠ',
    'inactive_title' => 'Šis noteikums nedarbojas. Noteikums izslēdzas, kad tiek dzēsta kategorija vai darījuma partneris, uz ko tas norāda.',

    'more_conditions' => 'vēl +:count',

    'delete_confirm' => 'Dzēst?',
    'delete_yes' => 'Jā, dzēst',
    'cancel' => 'Atcelt',
    'edit' => 'Rediģēt',
    'delete' => 'Dzēst',
    'edit_aria' => 'Rediģēt noteikumu (prioritāte :priority)',
    'delete_aria' => 'Dzēst noteikumu (prioritāte :priority)',

    'footer_note' => 'Noteikumi un tirgotāju vēsture darbojas kopā. Noteikuma dzēšana neizdzēš to, ko Beatrax ir iemācījies no iepriekšējām kategorizācijām — nākamajā importā tas joprojām var ieteikt to pašu kategoriju pēc vēstures.',

    'chip_category' => 'Kategorija: :path',
    'chip_counterparty' => 'Darījuma partneris: :path',
    'chip_note' => 'Piezīme',
    'chip_tax_tag' => 'Nodokļu atzīme',

    'flash_deleted' => 'Noteikums dzēsts.',
    'flash_not_found' => 'Noteikums nav atrasts (tas, iespējams, ir dzēsts citā cilnē).',
    'flash_saved' => 'Noteikums saglabāts.',
    'flash_reapplying' => 'Piemēro noteikumus jūsu vēsturei…',
    'summary_no_changes' => 'Nav izmaiņu — jūsu vēsture jau atbilst noteikumiem.',
    'summary_updated' => 'Atjaunināts: :fields, :transactions.',
    'summary_fields' => ':count lauku|:count lauks|:count lauki',
    'summary_transactions' => ':count darījumu|:count darījums|:count darījumi',
    'summary_reconciled_skipped' => 'Izlaisti :count saskaņotu darījumu.|Izlaists :count saskaņots darījums.|Izlaisti :count saskaņoti darījumi.',
];
