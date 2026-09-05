<?php

declare(strict_types=1);

return [
    'page_title' => 'Sortering af modparter',
    'heading' => 'Sortér ukendte modparter',

    'progress' => ':seen af :total · :percent % · ~:minutes min tilbage',
    'progress_aria' => 'Sorteringsforløb',

    'all_caught_aria' => 'Alle modparter er mærket',
    'all_caught_heading' => '🎉 Alt er klaret — hver modpart er mærket.',
    'back_to_index' => 'Tilbage til modparter →',

    'meta' => ':count transaktion · sidst set :date|:count transaktioner · sidst set :date',

    'suggested_aria' => 'Foreslået match',
    'suggestion_medium' => '✨ Måske **:name** — middel sikkerhed',
    'suggestion_low' => 'Mønstermatch: **:name** — lav sikkerhed. Kontrollér, før du kobler.',
    'suggestion_high' => '✨ Ligner **:name** — høj sikkerhed',

    'reasoning' => ':hits af :total nylig postering på denne IBAN peger på :name.|:hits af :total nylige posteringer på denne IBAN peger på :name.',
    'yes_link' => 'Ja, kobl til :name ↵',
    'no_not' => 'Nej, ikke :name',

    'recent_on_iban' => 'Seneste transaktioner på dette IBAN-nummer',
    'recent_on_counterparty' => 'Seneste transaktioner med denne modpart',
    'no_transactions_yet' => 'Ingen transaktioner registreret endnu.',

    'label_manually' => 'Eller mærk manuelt',
    'label_question' => 'Hvad er denne modpart?',
    'display_name_label' => 'Visningsnavn',
    'type_label' => 'Type',
    'type_merchant' => 'Forhandler',
    'type_personal' => 'Privat',
    'type_bank' => 'Bank',
    'type_government' => 'Offentlig myndighed',
    'save_label' => 'Gem mærkning',
    'name_required' => 'Giv først denne modpart et navn.',
    'draft_kept' => 'Det, du skriver, bevares, mens du bevæger dig gennem køen.',

    'skip' => 'Spring over indtil videre',
    'mark_ignored' => 'Spørg ikke om denne igen',
    'skip_note' => 'At springe over skriver ingenting — det går bare videre til den næste ukendte.',
    'mark_ignored_note' => 'Det markerer modparten som ignoreret, så den holdes ude af denne kø. Dens navn, type og historik røres ikke, og du kan stadig mærke den senere på siden Modparter.',
    'previous' => 'Forrige ukendte',

    'kbd_yes' => 'ja',
    'kbd_no' => 'nej',
    'kbd_skip' => 'spring over',
    'kbd_next' => 'næste',

    'footer' => ':seen allerede mærket · :count tilbage',
];
