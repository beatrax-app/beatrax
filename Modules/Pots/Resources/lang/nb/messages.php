<?php

declare(strict_types=1);

return [
    'page_title' => 'Sparepotter · Beatrax',
    'heading' => 'Sparepotter',
    'subtitle' => 'Virtuelle delsaldoer som alltid summerer seg til den faktiske kontosaldoen din.',
    'add_pot' => 'Legg til sparepott',

    'pot_fallback' => 'sparepott',

    'empty' => [
        'heading' => 'Ingen sparepotter ennå',
        'body' => 'Opprett virtuelle delsaldoer innenfor en hvilken som helst konto for å organisere pengene dine uten en faktisk bankoverføring.',
        'cta' => 'Legg til din første sparepott',
        'no_accounts_cta' => 'Importer en kontoutskrift',
    ],

    'common' => [
        'cancel' => 'Avbryt',
        'amount' => 'Beløp',
        'note_optional' => 'Notat (valgfritt)',
    ],

    'actions' => [
        'fund' => 'Sett inn',
        'move' => 'Flytt',
        'edit' => 'Rediger',
        'withdraw' => 'Ta ut',
        'archive' => 'Arkiver',
        'restore' => 'Gjenopprett',
    ],

    'recon' => [
        'over_allocated' => 'Sparepottene overstiger den faktiske saldoen med :amount — balanser på nytt for å rette det',
        'real_balance' => 'Faktisk saldo:',
        'allocated' => 'Fordelt:',
        'unallocated' => 'Ufordelt:',
    ],

    'chip' => [
        'goal' => 'Mål:',
        'goal_name_fallback' => 'Mål',
        'category_fallback' => 'Kategori',
    ],

    'coverage' => [
        'spent' => 'brukt',
        'in_pot' => 'i sparepotten',
    ],

    'archive_confirm' => 'Vil du arkivere denne sparepotten? Saldoen på :amount går tilbake til ufordelt.',
    'confirm_archive_aria' => 'Bekreft arkivering av :name',
    'more_actions_aria' => 'Flere handlinger for :name',

    'history' => [
        'show' => 'Vis historikk ↓',
        'hide' => 'Skjul historikk ↑',
    ],

    'movement' => [
        'fund' => 'Innskudd',
        'withdraw' => 'Uttak',
        'moved_from' => 'Flyttet fra :name',
        'moved_to' => 'Flyttet til :name',
    ],

    'archived' => [
        'toggle' => 'Arkiverte sparepotter (:count)',
        'badge' => 'Arkivert',
    ],

    'form' => [
        'create_title' => 'Opprett en sparepott',
        'edit_title' => 'Rediger sparepott',
        'create_subtitle' => 'Gi et virtuelt delsaldo innenfor en konto et navn.',
        'edit_subtitle' => 'Oppdater navnet eller tilknytningen for denne sparepotten.',
        'name' => 'Navn',
        'name_placeholder' => 'f.eks. Feriepenger',
        'account' => 'Konto',
        'select_account' => 'Velg en konto',
        'initial_amount' => 'Startbeløp (valgfritt)',
        'initial_amount_help' => 'Beløpet trekkes fra ufordelt. La feltet stå tomt for å opprette en tom sparepott.',
        'link_to' => 'Knytt til (valgfritt)',
        'link_goal' => 'Mål',
        'link_none' => 'Ingen',
        'select_goal' => 'Velg et mål',
        'save_pot' => 'Lagre sparepott',
        'save_changes' => 'Lagre endringer',
    ],

    'fund' => [
        'title' => 'Sett inn i sparepott',
        'heading' => 'Sett inn i :name',
        'submit' => 'Sett inn i sparepott',
        'note_placeholder' => 'f.eks. Månedlig sparing',
        'available' => 'Tilgjengelig å fordele: :amount (ufordelt)',
    ],

    'move' => [
        'title' => 'Flytt midler',
        'heading' => 'Flytt fra :name',
        'to' => 'Flytt til',
        'select_pot' => 'Velg en sparepott',
        'no_others_short' => 'Ingen andre sparepotter',
        'no_others' => 'Ingen andre sparepotter på denne kontoen',
        'submit' => 'Flytt midler',
        'note_placeholder' => 'f.eks. Overføring til ferie',
    ],

    'withdraw' => [
        'heading' => 'Ta ut fra :name',
        'note_placeholder' => 'f.eks. Uttak',
    ],

    'available_in' => 'Tilgjengelig i :name: :amount',

    'errors' => [
        'enter_name' => 'Skriv inn et navn på denne sparepotten.',
        'select_account' => 'Velg en konto for denne sparepotten.',
        'amount_exceeds_unallocated' => 'Beløpet overstiger den ufordelte saldoen.',
        'amount_exceeds_unallocated_available' => 'Beløpet overstiger den ufordelte saldoen (:amount tilgjengelig).',
        'amount_exceeds_pot_balance' => 'Beløpet overstiger saldoen i :name (:amount tilgjengelig).',
        'generic' => 'Potten kunne ikke lagres. Sjekk feltene og prøv igjen.',
        'amount_invalid' => 'Angi et beløp større enn null.',
        'goal_already_linked' => 'Dette målet har allerede en aktiv tilknyttet pott. Arkiver den først.',
    ],

    'toast' => [
        'pot_created' => 'Sparepotten er opprettet.',
        'pot_updated' => 'Sparepotten er oppdatert.',
        'pot_funded' => 'Det er satt inn i sparepotten.',
        'withdrawn' => 'Det er tatt ut fra sparepotten.',
        'funds_moved' => 'Midlene er flyttet.',
        'pot_archived' => 'Sparepotten er arkivert.',
        'pot_restored' => 'Sparepotten er gjenopprettet.',
    ],
];
