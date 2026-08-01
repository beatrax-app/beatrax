<?php

declare(strict_types=1);

return [
    'page_title' => 'Transacties',
    'heading' => 'Transacties',

    'subtitle_searching' => 'Volledige historie doorzoeken',
    'subtitle_full' => 'Volledige historie.',
    'subtitle_recent' => 'Recente transacties (laatste 90 dagen).',

    'currency_aria' => 'Valutaweergave',
    'currency_eur' => 'Alleen EUR',
    'currency_original' => 'Oorspronkelijke valuta',

    'show_recent' => 'Alleen recente tonen',
    'show_full' => 'Volledige historie tonen',

    'empty_period' => 'Niets in deze periode.',

    'loading_more' => 'Meer transacties laden',
    'load_more' => 'Meer laden',

    'split_badge' => 'Splitsing · :count',
    'split_expand_aria' => 'Gesplitst over :count categorieën — klap uit om te bekijken',

    'chain_badge' => 'reeks',
    'chain_title' => 'Onderdeel van een reeks — open deze regel om te bekijken',

    'table' => [
        'date' => 'Datum',
        'counterparty' => 'Tegenpartij',
        'category' => 'Categorie',
        'tax' => 'Belasting',
        'status' => 'Status',
        'amount' => 'Bedrag',
    ],

    'search' => [
        'placeholder' => 'Zoek winkelier, omschrijving, notities…',
        'placeholder_short' => 'Transacties zoeken…',
        'aria' => 'Transacties zoeken',
        'clear_all' => 'Alles wissen',
        'filters' => 'Filters',
        'open_filters_aria' => 'Filters openen',
        'apply' => 'Toepassen',
        'clear' => 'Wissen',
        'count_one' => ':count transactie',
        'count_many' => ':count transacties',
        'matching_suffix' => 'die aan de filters voldoen',
        'flow' => ':out uit / :in in',
    ],

    'no_results' => [
        'heading' => 'Geen resultaten',
        'remove_prompt' => 'Probeer een filter te verwijderen dat de resultaten beperkt:',
        'no_match_query' => 'Geen transacties komen overeen met “:query” in de volledige historie.',
        'no_match_filters' => 'Geen transacties komen overeen met de toegepaste filters.',
        'did_you_mean' => 'Bedoelde je:',
        'account_fallback' => 'Rekening :id',
        'category_fallback' => 'Categorie :id',
    ],

    'filter' => [
        'date' => 'Datum',
        'account' => 'Rekening',
        'amount' => 'Bedrag',
        'category' => 'Categorie',
        'date_range' => 'Datumbereik',
        'from' => 'Van',
        'to' => 'Tot',
        'custom_range' => 'Aangepast bereik ×',
        'after' => 'Na :date ×',
        'before' => 'Voor :date ×',
        'dir_both' => 'Beide',
        'dir_in' => 'In',
        'dir_out' => 'Uit',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Minimumbedrag',
        'max_aria' => 'Maximumbedrag',
        'after_aria' => 'Na datum',
        'before_aria' => 'Voor datum',
        'acct_one' => ':count rekening',
        'acct_many' => ':count rekeningen',
        'cat_one' => ':count categorie',
        'cat_many' => ':count categorieën',
        'date_dialog' => 'Datumfilter',
        'account_dialog' => 'Rekeningfilter',
        'amount_dialog' => 'Bedragfilter',
        'category_dialog' => 'Categoriefilter',
        'remove_date_aria' => 'Datumfilter verwijderen',
        'remove_account_aria' => 'Rekeningfilter verwijderen',
        'remove_amount_aria' => 'Bedragfilter verwijderen',
        'remove_category_aria' => 'Categoriefilter verwijderen',
        'remove_named_aria' => 'Filter :name verwijderen',
    ],

    'date_preset' => [
        'this_month' => 'Deze maand',
        'last_month' => 'Vorige maand',
        'this_year' => 'Dit jaar',
        'last_year' => 'Vorig jaar',
    ],
];
