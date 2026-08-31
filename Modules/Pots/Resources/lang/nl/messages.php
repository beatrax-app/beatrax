<?php

declare(strict_types=1);

return [
    'page_title' => 'Potjes · Beatrax',
    'heading' => 'Potjes',
    'subtitle' => 'Virtuele deelsaldo\'s die je afzondert van je werkelijke rekeningsaldo.',
    'add_pot' => 'Potje toevoegen',
    'pot_fallback' => 'potje',

    'empty' => [
        'heading' => 'Nog geen potjes',
        'body' => 'Maak virtuele deelsaldo\'s binnen een rekening om je geld te organiseren zonder een echte bankoverboeking.',
        'cta' => 'Maak je eerste potje',
        'no_accounts_cta' => 'Een afschrift importeren',
    ],

    'common' => [
        'cancel' => 'Annuleren',
        'amount' => 'Bedrag',
        'note_optional' => 'Notitie (optioneel)',
    ],

    'actions' => [
        'fund' => 'Storten',
        'move' => 'Verplaatsen',
        'edit' => 'Bewerken',
        'withdraw' => 'Opnemen',
        'archive' => 'Archiveren',
        'restore' => 'Herstellen',
    ],

    'recon' => [
        'over_allocated' => 'Potjes overschrijden het werkelijke saldo met :amount — herverdeel om dit op te lossen',
        'real_balance' => 'Werkelijk saldo:',
        'allocated' => 'Toegewezen:',
        'unallocated' => 'Niet-toegewezen:',
    ],

    'chip' => [
        'goal' => 'Doel:',
        'goal_name_fallback' => 'Doel',
        'category_fallback' => 'Categorie',
    ],

    'coverage' => [
        'spent' => 'uitgegeven',
        'in_pot' => 'in potje',
    ],

    'archive_confirm' => 'Dit potje archiveren? Het saldo van :amount keert terug naar niet-toegewezen.',
    'confirm_archive_aria' => 'Bevestig archivering van :name',
    'more_actions_aria' => 'Meer acties voor :name',

    'history' => [
        'show' => 'Geschiedenis tonen ↓',
        'hide' => 'Geschiedenis verbergen ↑',
        'truncated' => 'Recentste mutaties: :shown van :count',
    ],

    'movement' => [
        'fund' => 'Storting',
        'withdraw' => 'Opname',
        'moved_from' => 'Verplaatst van :name',
        'moved_to' => 'Verplaatst naar :name',
        'unreadable' => 'Vastgelegd door een nieuwere versie van Beatrax',
        'released_on_archive' => 'Vrijgegeven bij archivering',
    ],

    'archived' => [
        'toggle' => 'Gearchiveerd potje (:count)|Gearchiveerde potjes (:count)',
        'badge' => 'Gearchiveerd',
    ],

    'form' => [
        'create_title' => 'Een potje aanmaken',
        'edit_title' => 'Potje bewerken',
        'create_subtitle' => 'Geef een virtueel deelsaldo binnen een rekening een naam.',
        'edit_subtitle' => 'Werk de naam of koppeling van dit potje bij.',
        'name' => 'Naam',
        'name_placeholder' => 'bijv. Vakantiepot',
        'account' => 'Rekening',
        'select_account' => 'Kies een rekening',
        'initial_amount' => 'Startbedrag (optioneel)',
        'initial_amount_help' => 'Het bedrag wordt afgetrokken van niet-toegewezen. Laat leeg om leeg aan te maken.',
        'link_to' => 'Koppelen aan (optioneel)',
        'link_goal' => 'Doel',
        'link_none' => 'Geen',
        'select_goal' => 'Kies een doel',
        'save_pot' => 'Potje opslaan',
        'save_changes' => 'Wijzigingen opslaan',
    ],

    'fund' => [
        'title' => 'Storten in potje',
        'heading' => 'Storten in :name',
        'submit' => 'Storten in potje',
        'note_placeholder' => 'bijv. Maandelijkse spaarstorting',
        'available' => 'Beschikbaar om toe te wijzen: :amount (niet-toegewezen)',
    ],

    'move' => [
        'title' => 'Geld verplaatsen',
        'heading' => 'Verplaatsen vanuit :name',
        'to' => 'Verplaatsen naar',
        'select_pot' => 'Kies een potje',
        'no_others_short' => 'Geen andere potjes',
        'no_others' => 'Geen andere potjes op deze rekening',
        'submit' => 'Geld verplaatsen',
        'note_placeholder' => 'bijv. Overboeking voor vakantie',
    ],

    'withdraw' => [
        'heading' => 'Opnemen uit :name',
        'note_placeholder' => 'bijv. Opname',
    ],

    'available_in' => 'Beschikbaar in :name: :amount',

    'errors' => [
        'enter_name' => 'Voer een naam in voor dit potje.',
        'select_account' => 'Kies een rekening voor dit potje.',
        'amount_exceeds_unallocated_available' => 'Bedrag overschrijdt het niet-toegewezen saldo (:amount beschikbaar).',
        'amount_exceeds_pot_balance' => 'Bedrag overschrijdt het saldo in :name (:amount beschikbaar).',
        'generic' => 'Dit potje kon niet worden opgeslagen. Controleer de velden en probeer het opnieuw.',
        'amount_invalid' => 'Voer een bedrag groter dan nul in.',
        'goal_already_linked' => 'Dit doel heeft al een actief gekoppeld potje. Archiveer dat eerst.',
        'account_cannot_hold_pots' => 'Een potje heeft een rekening nodig waar geld op staat. Kies een andere rekening.',
        'select_target_pot' => 'Kies een potje om naar te verplaatsen.',
        'move_target_missing' => 'Dat potje is niet meer beschikbaar. Kies een ander.',
        'move_same_pot' => 'Een potje kan geen geld naar zichzelf verplaatsen. Kies een ander potje.',
        'move_cross_account' => 'Potjes wisselen alleen geld uit binnen één rekening, en :name staat op :account.',
        'pot_missing' => 'Dat potje is niet meer beschikbaar.',
        'operation_failed' => 'Dit is niet doorgegaan. Er is geen geld verplaatst — probeer het opnieuw.',
    ],

    'toast' => [
        'pot_created' => 'Potje aangemaakt.',
        'pot_updated' => 'Potje bijgewerkt.',
        'pot_funded' => 'Potje gevuld.',
        'withdrawn' => 'Opgenomen uit potje.',
        'funds_moved' => 'Geld verplaatst.',
        'pot_archived' => 'Potje gearchiveerd.',
        'pot_restored' => 'Potje hersteld.',
    ],
];
