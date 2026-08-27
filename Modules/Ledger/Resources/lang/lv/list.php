<?php

declare(strict_types=1);

return [
    'page_title' => 'Darījumi',
    'heading' => 'Darījumi',

    'subtitle_searching' => 'Meklē visā vēsturē',
    'subtitle_full' => 'Pilna vēsture.',
    'subtitle_recent' => 'Nesenie darījumi (pēdējās 90 dienas).',

    'currency_aria' => 'Valūtas skats',
    'currency_eur' => 'Tikai :code',
    'currency_original' => 'Sākotnējā valūta',

    'show_recent' => 'Rādīt tikai nesenos',
    'show_full' => 'Rādīt pilnu vēsturi',

    'empty_period' => 'Šajā periodā nekā nav.',


    'empty_recent_has_older' => 'Pēdējās 90 dienās nekā. Jūsu vecākie darījumi joprojām ir šeit.',

    'empty_history' => 'Darījumu vēl nav.',
    'loading_more' => 'Ielādē vairāk darījumu',
    'load_more' => 'Ielādēt vairāk',

    'split_badge' => 'Sadalīts · :count',
    'split_expand_aria' => 'Sadalīts :count kategorijā — izvērsiet, lai skatītu|Sadalīts :count kategorijā — izvērsiet, lai skatītu|Sadalīts :count kategorijās — izvērsiet, lai skatītu',

    'chain_badge' => 'ķēde',
    'chain_title' => 'Daļa no ķēdes — atveriet šo rindu, lai skatītu',

    'table' => [
        'date' => 'Datums',
        'counterparty' => 'Darījuma partneris',
        'category' => 'Kategorija',
        'tax' => 'Nodokļi',
        'status' => 'Statuss',
        'amount' => 'Summa',
    ],

    'search' => [
        'placeholder' => 'Meklēt tirgotāju, aprakstu, piezīmes…',
        'placeholder_short' => 'Meklēt darījumus…',
        'aria' => 'Meklēt darījumus',
        'clear_all' => 'Notīrīt visu',
        'filters' => 'Filtri',
        'open_filters_aria' => 'Atvērt filtrus',
        'apply' => 'Lietot',
        'clear' => 'Notīrīt',

        'count' => ':count darījumu|:count darījums|:count darījumi',
        'matching_suffix' => 'atbilst filtriem',
        'flow' => ':out ārā / :in iekšā',
    ],

    'no_results' => [
        'heading' => 'Nekas neatbilst',
        'remove_prompt' => 'Mēģiniet noņemt filtru, kas varētu sašaurināt rezultātus:',
        'no_match_query' => 'Visā vēsturē neviens darījums neatbilst “:query”.',
        'no_match_filters' => 'Neviens darījums neatbilst lietotajiem filtriem.',
        'did_you_mean' => 'Vai domājāt:',
        'account_fallback' => 'Konts :id',
        'category_fallback' => 'Kategorija :id',
    ],

    'filter' => [
        'date' => 'Datums',
        'account' => 'Konts',
        'amount' => 'Summa',
        'category' => 'Kategorija',
        'date_range' => 'Datumu diapazons',
        'from' => 'No',
        'to' => 'Līdz',
        'custom_range' => 'Pielāgots diapazons ×',
        'after' => 'Pēc :date ×',
        'before' => 'Pirms :date ×',
        'dir_both' => 'Abi',
        'dir_in' => 'Ienākošie',
        'dir_out' => 'Izejošie',
        'min' => 'Min.',
        'max' => 'Maks.',
        'min_aria' => 'Minimālā summa',
        'max_aria' => 'Maksimālā summa',
        'after_aria' => 'Pēc datuma',
        'before_aria' => 'Pirms datuma',
        'acct' => ':count kontu|:count konts|:count konti',
        'cat' => ':count kategoriju|:count kategorija|:count kategorijas',
        'date_dialog' => 'Datuma filtrs',
        'account_dialog' => 'Konta filtrs',
        'amount_dialog' => 'Summas filtrs',
        'category_dialog' => 'Kategorijas filtrs',
        'remove_date_aria' => 'Noņemt datuma filtru',
        'remove_account_aria' => 'Noņemt konta filtru',
        'remove_amount_aria' => 'Noņemt summas filtru',
        'remove_category_aria' => 'Noņemt kategorijas filtru',

        'remove_named_aria' => 'Noņemt filtru :name',
    ],

    'date_preset' => [
        'this_month' => 'Šis mēnesis',
        'last_month' => 'Pagājušais mēnesis',
        'this_year' => 'Šis gads',
        'last_year' => 'Pagājušais gads',
    ],
];
