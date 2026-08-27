<?php

declare(strict_types=1);

return [
    'page_title' => 'Opsparingspuljer · Beatrax',
    'heading' => 'Opsparingspuljer',
    'subtitle' => 'Virtuelle delsaldi, der altid summer op til din reelle kontosaldo.',
    'add_pot' => 'Tilføj pulje',

    'pot_fallback' => 'pulje',

    'empty' => [
        'heading' => 'Ingen puljer endnu',
        'body' => 'Opret virtuelle delsaldi inden for en hvilken som helst konto for at organisere dine penge uden en reel bankoverførsel.',
        'cta' => 'Tilføj din første pulje',
        'no_accounts_cta' => 'Importér et kontoudtog',
    ],

    'common' => [
        'cancel' => 'Annullér',
        'amount' => 'Beløb',
        'note_optional' => 'Note (valgfrit)',
    ],

    'actions' => [
        'fund' => 'Indsæt',
        'move' => 'Flyt',
        'edit' => 'Redigér',
        'withdraw' => 'Hæv',
        'archive' => 'Arkivér',
        'restore' => 'Gendan',
    ],

    'recon' => [
        'over_allocated' => 'Puljerne overstiger den reelle saldo med :amount — genbalancér for at rette det',
        'real_balance' => 'Reel saldo:',
        'allocated' => 'Fordelt:',
        'unallocated' => 'Ufordelt:',
    ],

    'chip' => [
        'goal' => 'Mål:',
        'goal_name_fallback' => 'Mål',
        'category_fallback' => 'Kategori',
    ],

    'coverage' => [
        'spent' => 'brugt',
        'in_pot' => 'i puljen',
    ],

    'archive_confirm' => 'Vil du arkivere denne pulje? Saldoen på :amount går tilbage til ufordelt.',
    'confirm_archive_aria' => 'Bekræft arkivering af :name',
    'more_actions_aria' => 'Flere handlinger for :name',

    'history' => [
        'show' => 'Vis historik ↓',
        'hide' => 'Skjul historik ↑',
    ],

    'movement' => [
        'fund' => 'Indsættelse',
        'withdraw' => 'Hævning',
        'moved_from' => 'Flyttet fra :name',
        'moved_to' => 'Flyttet til :name',
    ],

    'archived' => [
        'toggle' => 'Arkiverede puljer (:count)',
        'badge' => 'Arkiveret',
    ],

    'form' => [
        'create_title' => 'Opret en pulje',
        'edit_title' => 'Redigér pulje',
        'create_subtitle' => 'Giv et virtuelt delsaldo inden for en konto et navn.',
        'edit_subtitle' => 'Opdatér navnet eller tilknytningen for denne pulje.',
        'name' => 'Navn',
        'name_placeholder' => 'f.eks. Ferieopsparing',
        'account' => 'Konto',
        'select_account' => 'Vælg en konto',
        'initial_amount' => 'Startbeløb (valgfrit)',
        'initial_amount_help' => 'Beløbet trækkes fra ufordelt. Lad feltet stå tomt for at oprette en tom pulje.',
        'link_to' => 'Tilknyt til (valgfrit)',
        'link_goal' => 'Mål',
        'link_none' => 'Ingen',
        'select_goal' => 'Vælg et mål',
        'save_pot' => 'Gem pulje',
        'save_changes' => 'Gem ændringer',
    ],

    'fund' => [
        'title' => 'Indsæt på pulje',
        'heading' => 'Indsæt på :name',
        'submit' => 'Indsæt på pulje',
        'note_placeholder' => 'f.eks. Månedlig opsparing',
        'available' => 'Til rådighed at fordele: :amount (ufordelt)',
    ],

    'move' => [
        'title' => 'Flyt midler',
        'heading' => 'Flyt fra :name',
        'to' => 'Flyt til',
        'select_pot' => 'Vælg en pulje',
        'no_others_short' => 'Ingen andre puljer',
        'no_others' => 'Ingen andre puljer på denne konto',
        'submit' => 'Flyt midler',
        'note_placeholder' => 'f.eks. Overførsel til ferie',
    ],

    'withdraw' => [
        'heading' => 'Hæv fra :name',
        'note_placeholder' => 'f.eks. Hævning',
    ],

    'available_in' => 'Til rådighed i :name: :amount',

    'errors' => [
        'enter_name' => 'Indtast et navn til denne pulje.',
        'select_account' => 'Vælg en konto til denne pulje.',
        'amount_exceeds_unallocated' => 'Beløbet overstiger den ufordelte saldo.',
        'amount_exceeds_unallocated_available' => 'Beløbet overstiger den ufordelte saldo (:amount til rådighed).',
        'amount_exceeds_pot_balance' => 'Beløbet overstiger saldoen i :name (:amount til rådighed).',
        'generic' => 'Puljen kunne ikke gemmes. Tjek felterne, og prøv igen.',
        'amount_invalid' => 'Angiv et beløb større end nul.',
        'goal_already_linked' => 'Dette mål har allerede en aktiv tilknyttet pulje. Arkivér den først.',
    ],

    'toast' => [
        'pot_created' => 'Puljen er oprettet.',
        'pot_updated' => 'Puljen er opdateret.',
        'pot_funded' => 'Der er indsat på puljen.',
        'withdrawn' => 'Der er hævet fra puljen.',
        'funds_moved' => 'Midlerne er flyttet.',
        'pot_archived' => 'Puljen er arkiveret.',
        'pot_restored' => 'Puljen er gendannet.',
    ],
];
