<?php

declare(strict_types=1);

return [
    'page_title' => 'Hranilniki · Beatrax',
    'heading' => 'Hranilniki',
    'subtitle' => 'Navidezna delna stanja, izločena iz dejanskega stanja računa.',
    'add_pot' => 'Dodaj hranilnik',

    'pot_fallback' => 'hranilnik',

    'empty' => [
        'heading' => 'Hranilnikov še ni',
        'body' => 'Ustvari navidezna delna stanja znotraj poljubnega računa in razporedi denar brez dejanskega bančnega prenosa.',
        'cta' => 'Dodaj svoj prvi hranilnik',
        'no_accounts_cta' => 'Uvozi izpisek',
    ],

    'common' => [
        'cancel' => 'Prekliči',
        'amount' => 'Znesek',
        'note_optional' => 'Opomba (neobvezno)',
    ],

    'actions' => [
        'fund' => 'Napolni',
        'move' => 'Prenesi',
        'edit' => 'Uredi',
        'withdraw' => 'Dvigni',
        'archive' => 'Arhiviraj',
        'restore' => 'Obnovi',
    ],

    'recon' => [
        'over_allocated' => 'Hranilniki presegajo dejansko stanje za :amount — uravnoteži jih',
        'real_balance' => 'Dejansko stanje:',
        'allocated' => 'Razporejeno:',
        'unallocated' => 'Nerazporejeno:',
    ],

    'chip' => [
        'goal' => 'Cilj:',
        'goal_name_fallback' => 'Cilj',
        'category_fallback' => 'Kategorija',
    ],

    'coverage' => [
        'spent' => 'porabljeno',
        'in_pot' => 'v hranilniku',
    ],

    'archive_confirm' => 'Arhivirati ta hranilnik? Stanje :amount se bo vrnilo med nerazporejeno.',
    'confirm_archive_aria' => 'Potrdi arhiviranje hranilnika :name',
    'more_actions_aria' => 'Več dejanj za hranilnik :name',

    'history' => [
        'show' => 'Prikaži zgodovino ↓',
        'hide' => 'Skrij zgodovino ↑',
        'truncated' => 'Zadnji premiki: :shown od :count',
    ],

    'movement' => [
        'fund' => 'Polnjenje',
        'withdraw' => 'Dvig',
        'moved_from' => 'Preneseno iz hranilnika :name',
        'moved_to' => 'Preneseno v hranilnik :name',
        'unreadable' => 'Zabeleženo z novejšo različico Beatraxa',
        'released_on_archive' => 'Sproščeno ob arhiviranju',
    ],

    'archived' => [
        'toggle' => 'Arhiviran hranilnik (:count)|Arhivirana hranilnika (:count)|Arhivirani hranilniki (:count)|Arhiviranih hranilnikov (:count)',
        'badge' => 'Arhivirano',
    ],

    'form' => [
        'create_title' => 'Ustvari hranilnik',
        'edit_title' => 'Uredi hranilnik',
        'create_subtitle' => 'Poimenuj navidezno delno stanje znotraj računa.',
        'edit_subtitle' => 'Posodobi ime ali povezavo tega hranilnika.',
        'name' => 'Ime',
        'name_placeholder' => 'npr. Sklad za dopust',
        'account' => 'Račun',
        'select_account' => 'Izberi račun',
        'initial_amount' => 'Začetni znesek (neobvezno)',
        'initial_amount_help' => 'Znesek se odšteje od nerazporejenega. Pusti prazno za prazen hranilnik.',
        'link_to' => 'Poveži z (neobvezno)',
        'link_goal' => 'Cilj',
        'link_none' => 'Brez',
        'select_goal' => 'Izberi cilj',
        'save_pot' => 'Shrani hranilnik',
        'save_changes' => 'Shrani spremembe',
    ],

    'fund' => [
        'title' => 'Napolni hranilnik',
        'heading' => 'Napolni hranilnik :name',
        'submit' => 'Napolni hranilnik',
        'note_placeholder' => 'npr. Mesečno varčevanje',
        'available' => 'Na voljo za razporeditev: :amount (nerazporejeno)',
    ],

    'move' => [
        'title' => 'Prenesi sredstva',
        'heading' => 'Prenesi iz hranilnika :name',
        'to' => 'Prenesi v',
        'select_pot' => 'Izberi hranilnik',
        'no_others_short' => 'Ni drugih hranilnikov',
        'no_others' => 'Na tem računu ni drugih hranilnikov',
        'submit' => 'Prenesi sredstva',
        'note_placeholder' => 'npr. Prenos za dopust',
    ],

    'withdraw' => [
        'heading' => 'Dvigni iz hranilnika :name',
        'note_placeholder' => 'npr. Dvig',
    ],

    'available_in' => 'Na voljo v hranilniku :name: :amount',

    'errors' => [
        'enter_name' => 'Vnesi ime za ta hranilnik.',
        'select_account' => 'Izberi račun za ta hranilnik.',
        'amount_exceeds_unallocated_available' => 'Znesek presega nerazporejeno stanje (na voljo: :amount).',
        'amount_exceeds_pot_balance' => 'Znesek presega stanje v hranilniku :name (na voljo: :amount).',
        'generic' => 'Sklada ni bilo mogoče shraniti. Preverite polja in poskusite znova.',
        'amount_invalid' => 'Vnesite znesek, večji od nič.',
        'goal_already_linked' => 'Ta cilj že ima aktiven povezan sklad. Najprej ga arhivirajte.',
        'account_cannot_hold_pots' => 'Hranilnik potrebuje račun, na katerem je denar. Izberi drug račun.',
        'select_target_pot' => 'Izberi hranilnik, v katerega prenesti.',
        'move_target_missing' => 'Ta hranilnik ni več na voljo. Izberi drugega.',
        'move_same_pot' => 'Hranilnik ne more prenesti denarja vase. Izberi drug hranilnik.',
        'move_cross_account' => 'Hranilniki si izmenjujejo denar samo znotraj enega računa, :name pa je na računu :account.',
        'pot_missing' => 'Ta hranilnik ni več na voljo.',
        'operation_failed' => 'Ni šlo skozi. Noben denar ni bil prenesen — poskusi znova.',
    ],

    'toast' => [
        'pot_created' => 'Hranilnik je ustvarjen.',
        'pot_updated' => 'Hranilnik je posodobljen.',
        'pot_funded' => 'Hranilnik je napolnjen.',
        'withdrawn' => 'Dvignjeno iz hranilnika.',
        'funds_moved' => 'Sredstva so prenesena.',
        'pot_archived' => 'Hranilnik je arhiviran.',
        'pot_restored' => 'Hranilnik je obnovljen.',
    ],
];
