<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Budgetar',
        'subtitle' => 'Fördela allt — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Föregående period',
        'next_aria' => 'Nästa period',
    ],

    'ready' => [
        'label' => 'Klart att fördela',
        'overassigned' => 'Du har fördelat mer än du har — minska ett kuvert eller vänta på mer inkomst.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Inget är fördelat än',
        'copy_hint' => 'Kopiera förra månadens plan, eller klicka i en cell nedan för att börja fördela.',
        'first_hint' => 'Klicka i en cell nedan för att börja fördela din första månad.',
        'copy_button' => 'Kopiera förra månaden',
    ],

    'no_categories' => [
        'heading' => 'Inga utgiftskategorier än',
        'body' => 'Lägg till en utgiftskategori för att börja fördela pengar till den.',
    ],

    'table' => [
        'category' => 'Kategori',
        'assigned' => 'Fördelat',
        'spent' => 'Spenderat',
        'available' => 'Tillgängligt',
        'if_overspent' => 'Vid överskridande',
        'notify_at' => 'Meddela vid',
        'actions' => 'Åtgärder',
    ],

    'badge' => [
        'carries_negative' => 'För över minus',
        'unconverted_aria' => 'Utgifter i en valuta utan tillgänglig kurs räknas inte här — se översikten',
        'unconverted_title' => 'Utgifter utan tillgänglig kurs räknas inte här — se översikten',
        'over_budget' => ':count över budget',
    ],

    'row' => [
        'assigned_aria' => 'Fördelat för :category',
        'overspend_aria' => 'Om :category överskrids',
        'notify_aria' => 'Meddela mig vid procent använt för :category',
        'move_money' => 'Flytta pengar',
        'move' => 'Flytta',
    ],

    'overspend' => [
        'reduce' => 'Minska nästa månads klart att fördela',
        'carry' => 'För över minuset i det här kuvertet',
    ],

    'history' => [
        'show' => 'Visa historik ↓',
        'hide' => 'Dölj historik ↑',
        'moved_from' => 'Flyttat från :category',
        'moved_to' => 'Flyttat till :category',
        'undo' => 'Ångra',
    ],

    'phone' => [
        'spent' => 'Spenderat :amount',
        'available' => 'Tillgängligt :amount',
        'notify_at' => 'Meddela vid',
    ],

    'modal' => [
        'move_from' => 'Flytta från :name',
        'move_from_fallback' => 'kuvert',
        'move_to' => 'Flytta till',
        'no_other' => 'Inga andra kuvert',
        'select' => 'Välj ett kuvert',
        'amount' => 'Belopp',
        'available_in' => 'Tillgängligt i :name: :amount',
        'note' => 'Anteckning (valfritt)',
        'note_placeholder' => 't.ex. Täcker överskridande på restaurang',
        'cancel' => 'Avbryt',
        'move_funds' => 'Flytta medel',
    ],

    'glance' => [
        'see_all' => 'Se alla →',
    ],

    'notices' => [
        'invalid_amount' => 'Ange ett giltigt belopp.',
        'threshold_range' => 'Ange ett heltal mellan 1 och 200.',
        'copied_last_month' => 'Förra månadens plan kopierades.',
        'choose_envelope' => 'Välj ett kuvert att flytta pengar till.',
        'amount_positive' => 'Ange ett belopp större än noll.',
        'move_failed' => 'Kunde inte slutföra flytten — försök igen.',
        'money_moved' => 'Pengarna flyttades.',
        'move_undone' => 'Flytten ångrades.',
    ],

    'errors' => [
        'assigned_negative' => 'Fördelat belopp kan inte vara negativt.',
        'invalid_overspend_mode' => 'Ogiltigt läge för överskridande.',
        'threshold_range' => 'Meddelandetröskeln måste vara mellan 1 och 200.',
        'same_envelope' => 'Käll- och målkuvert måste vara olika.',
        'non_positive_amount' => 'Ogiltigt eller icke-positivt belopp.',
        'category_not_found' => 'Kategorin hittades inte eller är inte tillgänglig för användaren.',
    ],
];
