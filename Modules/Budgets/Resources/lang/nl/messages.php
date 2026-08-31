<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Budgetten',
        'subtitle' => 'Wijs alles toe — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Vorige periode',
        'next_aria' => 'Volgende periode',
    ],

    'ready' => [
        'label' => 'Klaar om toe te wijzen',
        'overassigned' => 'Je hebt meer toegewezen dan je hebt — verlaag een envelop of wacht op meer inkomsten.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Nog niets toegewezen',
        'copy_hint' => 'Kopieer het plan van vorige maand of klik hieronder in een cel om te beginnen met toewijzen.',
        'first_hint' => 'Klik hieronder in een cel om je eerste maand toe te wijzen.',
        'copy_button' => 'Vorige maand kopiëren',
    ],

    'no_categories' => [
        'heading' => 'Nog geen uitgavencategorieën',
        'body' => 'Voeg een uitgavencategorie toe om er geld aan toe te wijzen.',
    ],

    'table' => [
        'category' => 'Categorie',
        'assigned' => 'Toegewezen',
        'carried_in' => 'Overgedragen',
        'moved' => 'Verplaatst',
        'spent' => 'Besteed',
        'available' => 'Beschikbaar',
        'if_overspent' => 'Bij overschrijding',
        'notify_at' => 'Melden bij',
        'actions' => 'Acties',
    ],

    'badge' => [
        'carries_negative' => 'Neemt negatief mee',
        'unconverted_aria' => 'Uitgaven in een valuta zonder beschikbare koers tellen hier niet mee — zie het dashboard',
        'unconverted_title' => 'Uitgaven zonder beschikbare koers tellen hier niet mee — zie het dashboard',
        'over_budget' => ':count over budget',
    ],

    'row' => [
        'assigned_aria' => 'Toegewezen voor :category',
        'overspend_aria' => 'Als :category wordt overschreden',
        'notify_aria' => 'Meld me bij percentage gebruikt voor :category',
        'move_money' => 'Geld verplaatsen',
        'move' => 'Verplaatsen',
    ],

    'overspend' => [
        'reduce' => 'Verlaag het toe te wijzen bedrag van volgende maand',
        'carry' => 'Neem het negatieve saldo mee in deze envelop',
    ],

    'history' => [
        'show' => 'Geschiedenis tonen ↓',
        'hide' => 'Geschiedenis verbergen ↑',
        'moved_from' => 'Verplaatst van :category',
        'moved_to' => 'Verplaatst naar :category',
        'moved_unreadable' => 'Verplaatst met :category door een nieuwere versie van Beatrax',
        'undo' => 'Ongedaan maken',
    ],

    'phone' => [
        'spent' => 'Besteed :amount',
        'carried_in' => 'Overgedragen :amount',
        'moved' => 'Verplaatst :amount',
        'available' => 'Beschikbaar :amount',
        'notify_at' => 'Melden bij',
    ],

    'modal' => [
        'move_from' => 'Verplaatsen van :name',
        'move_from_fallback' => 'envelop',
        'move_to' => 'Verplaatsen naar',
        'no_other' => 'Geen andere enveloppen',
        'select' => 'Selecteer een envelop',
        'amount' => 'Bedrag',
        'available_in' => 'Beschikbaar in :name: :amount',
        'note' => 'Notitie (optioneel)',
        'note_placeholder' => 'bijv. Overschrijding eten dekken',
        'cancel' => 'Annuleren',
        'move_funds' => 'Geld verplaatsen',
    ],

    'glance' => [
        'see_all' => 'Alles bekijken →',
    ],

    'notices' => [
        'invalid_amount' => 'Voer een geldig bedrag in.',
        'threshold_range' => 'Voer een heel getal tussen 1 en 200 in.',
        'copied_last_month' => 'Plan van vorige maand gekopieerd.',
        'choose_envelope' => 'Kies een envelop om geld naartoe te verplaatsen.',
        'amount_positive' => 'Voer een bedrag groter dan nul in.',
        'move_failed' => 'De verplaatsing kon niet worden voltooid — probeer het opnieuw.',
        'money_moved' => 'Geld verplaatst.',
        'move_undone' => 'Verplaatsing ongedaan gemaakt.',
    ],

    'errors' => [
        'assigned_negative' => 'Toegewezen bedrag kan niet negatief zijn.',
        'invalid_overspend_mode' => 'Ongeldige overschrijdingsmodus.',
        'threshold_range' => 'Meldingsdrempel moet tussen 1 en 200 liggen.',
        'same_envelope' => 'Bron- en bestemmingsenvelop moeten verschillend zijn.',
        'non_positive_amount' => 'Ongeldig of niet-positief bedrag.',
        'category_not_found' => 'Categorie niet gevonden of niet toegankelijk voor gebruiker.',
    ],
];
