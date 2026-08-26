<?php

declare(strict_types=1);

return [
    'page_title' => 'Transactie',
    'heading' => 'Transactie',

    'counterparty' => 'Tegenpartij',
    'description' => 'Omschrijving',
    'amount_native' => 'Bedrag (oorspronkelijk)',
    'amount_settled' => 'Bedrag (verrekend)',
    'effective_rate' => 'Effectieve koers',
    'ics_markup' => 'Inclusief eventuele ICS-opslag.',

    'split' => [
        'category' => 'Categorie',
        'open' => 'Splitsen over categorieën',
        'heading' => 'Splitsen over categorieën',
        'total' => 'Totaal :amount',
        'tax_per_category' => 'Belastinglabels worden hieronder per categorie ingesteld.',
        'choose_category' => 'Kies een categorie',
        'note_label' => 'Notitie',
        'note_placeholder' => 'Notitie (optioneel)',
        'tax_deductible' => 'Fiscaal aftrekbaar',
        'remove_leg_aria' => 'Deze categorie verwijderen',
        'add_category' => '+ Categorie toevoegen',
        'soft_cap' => ':count van ~20 categorieën — overweeg kleine bedragen te groeperen.',
        'remaining_zero' => 'Resterend :amount ✓',
        'remaining_to_assign' => 'Nog toe te wijzen: :amount',
        'over_allocated' => ':amount te veel toegewezen — verlaag een regel.',
        'save' => 'Splitsing opslaan',
        'saving' => 'Opslaan…',
        'unsplit' => 'Splitsing ongedaan maken',
        'remove_to_one' => 'Als je dit verwijdert, blijft één categorie over — de transactie wordt :category.',
        'remove_to_one_fallback' => 'deze categorie',
        'remove_category' => 'Categorie verwijderen',
        'keep_category' => 'Deze categorie behouden',
        'restore_single' => 'Herstellen als één categorie?',
        'survivor_legend' => 'Categorie om te behouden',
        'confirm_unsplit' => 'Ja, splitsing ongedaan maken',
        'keep_split' => 'Splitsing behouden',
    ],

    'tax' => [
        'section_aria' => 'Belastinglabel',
        'label' => 'Fiscaal aftrekbaar',
    ],

    'reclassify' => [
        'heading' => 'Herclassificeren',
        'help' => 'Overschrijf het gedetecteerde type. Als deze transactie aan een andere is gekoppeld, ontkoppelt een niet-overboekingstype beide zijden.',
        'choose_aria' => 'Kies een nieuw transactietype',
        'choose_option' => 'Kies een type…',
        'save' => 'Opslaan',
    ],

    'type_label' => [
        'expense' => 'Uitgave',
        'income' => 'Inkomsten',
        'transfer_out' => 'Uitgaande overboeking',
        'transfer_in' => 'Inkomende overboeking',
        'fee' => 'Kosten',
        'refund' => 'Terugbetaling',
        'adjustment' => 'Correctie',
    ],

    'note' => [
        'heading' => 'Notitie',
        'help' => 'Persoonlijke notitie bij deze transactie. Alleen zichtbaar voor jou.',
        'label' => 'Notitie',
        'placeholder' => 'Voeg een notitie toe…',
        'save' => 'Notitie opslaan',
        'saved' => 'Opgeslagen',
    ],

    'reassign' => [
        'heading' => 'Tegenpartij opnieuw toewijzen',
        'help' => 'Overschrijf de bepaalde tegenpartij voor deze transactie.',
        'choose_aria' => 'Kies tegenpartij',
        'choose_option' => 'Kies een tegenpartij…',
        'submit' => 'Opnieuw toewijzen',
    ],

    'goal' => [
        'heading' => 'Spaardoel',
        'help' => 'Laat deze transactie meetellen voor een van je spaardoelen.',
        'choose_aria' => 'Kies een spaardoel',
        'choose_option' => 'Kies een doel…',
        'submit' => 'Aan doel toevoegen',
        'remove_aria' => ':name verwijderen',
    ],

    'delete' => [
        'heading' => 'Transactie verwijderen',
        'help' => 'Verwijdert deze transactie permanent. Deze actie kan niet ongedaan worden gemaakt.',
        'button' => 'Verwijderen',
        'confirm_prompt' => 'Weet je het zeker?',
        'confirm' => 'Ja, verwijderen',
        'cancel' => 'Annuleren',
    ],

    'chain' => [
        'view' => 'Reeks bekijken',
    ],

    'toast' => [
        'reconciled_locked' => 'Deze transactie is afgestemd. Hef de afstemming op om wijzigingen te maken.',
        'reclassified_pair_removed' => 'Geherclassificeerd naar :type — koppeling verwijderd',
        'reclassified' => 'Geherclassificeerd naar :type',
        'note_saved' => 'Notitie opgeslagen',
        'unreconciled' => 'Afstemming opgeheven — je kunt deze transactie weer bewerken.',
        'counterparty_updated' => 'Tegenpartij bijgewerkt',
        'goal_attributed' => 'Telt mee voor dit doel',
        'goal_attribution_removed' => 'Telt niet meer mee voor dit doel',
        'split_saved' => 'Splitsing opgeslagen',
        'removed_one_remains' => 'Verwijderd — één categorie blijft over',
        'unsplit_restored' => 'Splitsing ongedaan gemaakt — hersteld naar één categorie',
    ],

    'errors' => [
        'totals_must_match' => 'Opslaan mislukt — de regeltotalen moeten precies gelijk zijn aan het transactietotaal.',
        'not_found' => 'Transactie niet gevonden.',
        'amount_zero' => 'Bedrag kan niet :amount zijn',
        'choose_category' => 'Kies een categorie.',
        'choose_before_removing' => 'Kies een categorie voordat je verwijdert.',
        'choose_before_unsplitting' => 'Kies een categorie voordat je de splitsing ongedaan maakt.',
        'not_found_or_unowned' => 'Transactie niet gevonden of niet van deze gebruiker.',
        'reconciled_split' => 'Deze transactie is afgestemd. Hef de afstemming op om de splitsing te wijzigen.',
        'not_splittable' => "Transactietype ':type' kan niet worden gesplitst.",
        'min_two_legs' => 'Een splitsing vereist ten minste 2 regels.',
        'legs_non_zero' => 'Regelbedragen mogen niet nul zijn.',
        'legs_parent_sign' => 'Regelbedragen moeten hetzelfde teken hebben als de hoofdtransactie.',
        'leg_category_not_accessible' => 'Regelcategorie niet gevonden of niet toegankelijk voor deze gebruiker.',
        'survivor_not_accessible' => 'Overblijvende categorie niet gevonden of niet toegankelijk voor deze gebruiker.',
        'survivor_must_be_current' => 'De overblijvende categorie moet een van de huidige regelcategorieën van de splitsing zijn.',
    ],
];
