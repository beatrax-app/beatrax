<?php

declare(strict_types=1);

return [
    'page_title' => 'Kogumispotid · Beatrax',
    'heading' => 'Kogumispotid',
    'subtitle' => 'Virtuaalsed alamjäägid, mis on eraldatud konto tegelikust jäägist.',
    'add_pot' => 'Lisa pott',

    'pot_fallback' => 'pott',

    'empty' => [
        'heading' => 'Kogumispotte veel pole',
        'body' => 'Loo mis tahes konto sees virtuaalseid alamjääke, et oma raha korrastada ilma päris pangaülekandeta.',
        'cta' => 'Lisa oma esimene pott',
        'no_accounts_cta' => 'Impordi kontoväljavõte',
    ],

    'common' => [
        'cancel' => 'Tühista',
        'amount' => 'Summa',
        'note_optional' => 'Märkus (valikuline)',
    ],

    'actions' => [
        'fund' => 'Lisa raha',
        'move' => 'Liiguta',
        'edit' => 'Muuda',
        'withdraw' => 'Võta välja',
        'archive' => 'Arhiveeri',
        'restore' => 'Taasta',
    ],

    'recon' => [
        'over_allocated' => 'Potid ületavad tegelikku jääki :amount võrra — paranda tasakaalustamisega',
        'real_balance' => 'Tegelik jääk:',
        'allocated' => 'Jaotatud:',
        'unallocated' => 'Jaotamata:',
    ],

    'chip' => [
        'goal' => 'Eesmärk:',
        'goal_name_fallback' => 'Eesmärk',
        'category_fallback' => 'Kategooria',
    ],

    'coverage' => [
        'spent' => 'kulutatud',
        'in_pot' => 'potis',
    ],

    'archive_confirm' => 'Kas arhiveerida see pott? Jääk :amount läheb tagasi jaotamata raha hulka.',
    'confirm_archive_aria' => 'Kinnita poti :name arhiveerimine',
    'more_actions_aria' => 'Rohkem toiminguid poti :name jaoks',

    'history' => [
        'show' => 'Näita ajalugu ↓',
        'hide' => 'Peida ajalugu ↑',
        'truncated' => 'Viimased liikumised: :shown / :count',
    ],

    'movement' => [
        'fund' => 'Lisatud',
        'withdraw' => 'Välja võetud',
        'moved_from' => 'Liigutatud potist :name',
        'moved_to' => 'Liigutatud potti :name',
        'unreadable' => 'Kirjutatud Beatraxi uuema versiooni poolt',
        'released_on_archive' => 'Vabastatud arhiveerimisel',
    ],

    'archived' => [
        'toggle' => 'Arhiveeritud pott (:count)|Arhiveeritud potid (:count)',
        'badge' => 'Arhiveeritud',
    ],

    'form' => [
        'create_title' => 'Loo pott',
        'edit_title' => 'Muuda potti',
        'create_subtitle' => 'Anna konto sees olevale virtuaalsele alamjäägile nimi.',
        'edit_subtitle' => 'Uuenda selle poti nime või seost.',
        'name' => 'Nimi',
        'name_placeholder' => 'nt Puhkusefond',
        'account' => 'Konto',
        'select_account' => 'Vali konto',
        'initial_amount' => 'Algsumma (valikuline)',
        'initial_amount_help' => 'Summa arvatakse jaotamata rahast maha. Jäta tühjaks, et luua tühi pott.',
        'link_to' => 'Seo (valikuline)',
        'link_goal' => 'Eesmärk',
        'link_none' => 'Puudub',
        'select_goal' => 'Vali eesmärk',
        'save_pot' => 'Salvesta pott',
        'save_changes' => 'Salvesta muudatused',
    ],

    'fund' => [
        'title' => 'Lisa potti raha',
        'heading' => 'Lisa raha potti :name',
        'submit' => 'Lisa potti raha',
        'note_placeholder' => 'nt Kuine sääst',
        'available' => 'Jaotamiseks saadaval: :amount (jaotamata)',
    ],

    'move' => [
        'title' => 'Liiguta raha',
        'heading' => 'Liiguta potist :name',
        'to' => 'Kuhu liigutada',
        'select_pot' => 'Vali pott',
        'no_others_short' => 'Teisi potte pole',
        'no_others' => 'Sellel kontol teisi potte pole',
        'submit' => 'Liiguta raha',
        'note_placeholder' => 'nt Ülekanne puhkuseks',
    ],

    'withdraw' => [
        'heading' => 'Võta potist :name välja',
        'note_placeholder' => 'nt Väljavõtmine',
    ],

    'available_in' => 'Potis :name saadaval: :amount',

    'errors' => [
        'enter_name' => 'Sisesta selle poti nimi.',
        'select_account' => 'Vali sellele potile konto.',
        'amount_exceeds_unallocated_available' => 'Summa ületab jaotamata jääki (saadaval :amount).',
        'amount_exceeds_pot_balance' => 'Summa ületab poti :name jääki (saadaval :amount).',
        'generic' => 'Rahakotti ei õnnestunud salvestada. Kontrollige välju ja proovige uuesti.',
        'amount_invalid' => 'Sisestage nullist suurem summa.',
        'goal_already_linked' => 'Sellel eesmärgil on juba aktiivne seotud rahakott. Arhiveerige see esmalt.',
        'account_cannot_hold_pots' => 'Pott vajab kontot, millel on raha. Vali teine konto.',
        'select_target_pot' => 'Vali pott, kuhu raha liigutada.',
        'move_target_missing' => 'See pott ei ole enam saadaval. Vali teine.',
        'move_same_pot' => 'Pott ei saa raha iseendasse liigutada. Vali teine pott.',
        'move_cross_account' => 'Potid vahetavad raha ainult ühe konto sees ja :name on kontol :account.',
        'pot_missing' => 'See pott ei ole enam saadaval.',
        'operation_failed' => 'See ei läinud läbi. Raha ei liigutatud — proovi uuesti.',
    ],

    'toast' => [
        'pot_created' => 'Pott on loodud.',
        'pot_updated' => 'Pott on uuendatud.',
        'pot_funded' => 'Potti lisati raha.',
        'withdrawn' => 'Potist võeti raha välja.',
        'funds_moved' => 'Raha on liigutatud.',
        'pot_archived' => 'Pott on arhiveeritud.',
        'pot_restored' => 'Pott on taastatud.',
    ],
];
