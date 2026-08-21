<?php

declare(strict_types=1);

return [
    'page_title' => 'Sparpotter · Beatrax',
    'heading' => 'Sparpotter',
    'subtitle' => 'Virtuella delsaldon som alltid summerar till ditt verkliga kontosaldo.',
    'add_pot' => 'Lägg till sparpott',

    'pot_fallback' => 'sparpott',

    'empty' => [
        'heading' => 'Inga sparpotter än',
        'body' => 'Skapa virtuella delsaldon inom vilket konto som helst för att organisera dina pengar utan en verklig banköverföring.',
        'cta' => 'Lägg till din första sparpott',
        'no_accounts_cta' => 'Importera ett kontoutdrag',
    ],

    'common' => [
        'cancel' => 'Avbryt',
        'amount' => 'Belopp',
        'note_optional' => 'Anteckning (valfritt)',
    ],

    'actions' => [
        'fund' => 'Fyll på',
        'move' => 'Flytta',
        'edit' => 'Redigera',
        'withdraw' => 'Ta ut',
        'archive' => 'Arkivera',
        'restore' => 'Återställ',
    ],

    'recon' => [
        'over_allocated' => 'Sparpotterna överstiger det verkliga saldot med :amount — balansera om för att åtgärda',
        'real_balance' => 'Verkligt saldo:',
        'allocated' => 'Fördelat:',
        'unallocated' => 'Ofördelat:',
    ],

    'chip' => [
        'goal' => 'Mål:',
        'goal_name_fallback' => 'Mål',
        'category_fallback' => 'Kategori',
    ],

    'coverage' => [
        'spent' => 'spenderat',
        'in_pot' => 'i sparpotten',
    ],

    'archive_confirm' => 'Vill du arkivera den här sparpotten? Saldot på :amount går tillbaka till ofördelat.',
    'confirm_archive_aria' => 'Bekräfta arkivering av :name',
    'more_actions_aria' => 'Fler åtgärder för :name',

    'history' => [
        'show' => 'Visa historik ↓',
        'hide' => 'Dölj historik ↑',
    ],

    'movement' => [
        'fund' => 'Påfyllning',
        'withdraw' => 'Uttag',
        'moved_from' => 'Flyttat från :name',
        'moved_to' => 'Flyttat till :name',
    ],

    'archived' => [
        'toggle' => 'Arkiverade sparpotter (:count)',
        'badge' => 'Arkiverad',
    ],

    'form' => [
        'create_title' => 'Skapa en sparpott',
        'edit_title' => 'Redigera sparpott',
        'create_subtitle' => 'Ge ett virtuellt delsaldo inom ett konto ett namn.',
        'edit_subtitle' => 'Uppdatera namnet eller kopplingen för den här sparpotten.',
        'name' => 'Namn',
        'name_placeholder' => 't.ex. Semesterkassa',
        'account' => 'Konto',
        'select_account' => 'Välj ett konto',
        'initial_amount' => 'Startbelopp (valfritt)',
        'initial_amount_help' => 'Beloppet dras från ofördelat. Lämna tomt för att skapa en tom sparpott.',
        'link_to' => 'Koppla till (valfritt)',
        'link_goal' => 'Mål',
        'link_none' => 'Ingen',
        'select_goal' => 'Välj ett mål',
        'save_pot' => 'Spara sparpott',
        'save_changes' => 'Spara ändringar',
    ],

    'fund' => [
        'title' => 'Fyll på sparpott',
        'heading' => 'Fyll på :name',
        'submit' => 'Fyll på sparpott',
        'note_placeholder' => 't.ex. Månadssparande',
        'available' => 'Tillgängligt att fördela: :amount (ofördelat)',
    ],

    'move' => [
        'title' => 'Flytta medel',
        'heading' => 'Flytta från :name',
        'to' => 'Flytta till',
        'select_pot' => 'Välj en sparpott',
        'no_others_short' => 'Inga andra sparpotter',
        'no_others' => 'Inga andra sparpotter på det här kontot',
        'submit' => 'Flytta medel',
        'note_placeholder' => 't.ex. Överföring till semestern',
    ],

    'withdraw' => [
        'heading' => 'Ta ut från :name',
        'note_placeholder' => 't.ex. Uttag',
    ],

    'available_in' => 'Tillgängligt i :name: :amount',

    'errors' => [
        'enter_name' => 'Ange ett namn för den här sparpotten.',
        'select_account' => 'Välj ett konto för den här sparpotten.',
        'amount_exceeds_unallocated' => 'Beloppet överstiger det ofördelade saldot.',
        'amount_exceeds_unallocated_available' => 'Beloppet överstiger det ofördelade saldot (:amount tillgängligt).',
        'amount_exceeds_pot_balance' => 'Beloppet överstiger saldot i :name (:amount tillgängligt).',
    ],

    'toast' => [
        'pot_created' => 'Sparpotten skapades.',
        'pot_updated' => 'Sparpotten uppdaterades.',
        'pot_funded' => 'Sparpotten fylldes på.',
        'withdrawn' => 'Uttag gjort från sparpotten.',
        'funds_moved' => 'Medlen flyttades.',
        'pot_archived' => 'Sparpotten arkiverades.',
        'pot_restored' => 'Sparpotten återställdes.',
    ],
];
