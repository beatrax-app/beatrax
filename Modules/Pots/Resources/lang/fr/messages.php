<?php

declare(strict_types=1);

return [
    'page_title' => 'Cagnottes · Beatrax',
    'heading' => 'Cagnottes',
    'subtitle' => 'Des sous-soldes virtuels dont la somme correspond toujours au solde réel de ton compte.',
    'add_pot' => 'Ajouter une cagnotte',

    'pot_fallback' => 'cagnotte',

    'empty' => [
        'heading' => 'Aucune cagnotte pour l\'instant',
        'body' => 'Crée des sous-soldes virtuels dans n\'importe quel compte pour organiser ton argent sans virement bancaire réel.',
        'cta' => 'Ajoute ta première cagnotte',
        'no_accounts_cta' => 'Importer un relevé',
    ],

    'common' => [
        'cancel' => 'Annuler',
        'amount' => 'Montant',
        'note_optional' => 'Note (facultatif)',
    ],

    'actions' => [
        'fund' => 'Alimenter',
        'move' => 'Déplacer',
        'edit' => 'Modifier',
        'withdraw' => 'Retirer',
        'archive' => 'Archiver',
        'restore' => 'Restaurer',
    ],

    'recon' => [
        'over_allocated' => 'Les cagnottes dépassent le solde réel de :amount — rééquilibre pour corriger',
        'real_balance' => 'Solde réel :',
        'allocated' => 'Affecté :',
        'unallocated' => 'Non affecté :',
    ],

    'chip' => [
        'goal' => 'Objectif :',
        'goal_name_fallback' => 'Objectif',
        'category_fallback' => 'Catégorie',
    ],

    'coverage' => [
        'spent' => 'dépensés',
        'in_pot' => 'dans la cagnotte',
    ],

    'archive_confirm' => 'Archiver cette cagnotte ? Le solde de :amount repassera en non affecté.',
    'confirm_archive_aria' => 'Confirmer l\'archivage de :name',
    'more_actions_aria' => 'Plus d\'actions pour :name',

    'history' => [
        'show' => 'Afficher l\'historique ↓',
        'hide' => 'Masquer l\'historique ↑',
    ],

    'movement' => [
        'fund' => 'Alimentation',
        'withdraw' => 'Retrait',
        'moved_from' => 'Déplacé depuis :name',
        'moved_to' => 'Déplacé vers :name',
    ],

    'archived' => [
        'toggle' => 'Cagnottes archivées (:count)',
        'badge' => 'Archivée',
    ],

    'form' => [
        'create_title' => 'Créer une cagnotte',
        'edit_title' => 'Modifier la cagnotte',
        'create_subtitle' => 'Donne un nom à un sous-solde virtuel dans un compte.',
        'edit_subtitle' => 'Modifie le nom ou le lien de cette cagnotte.',
        'name' => 'Nom',
        'name_placeholder' => 'ex. Fonds vacances',
        'account' => 'Compte',
        'select_account' => 'Choisis un compte',
        'initial_amount' => 'Montant initial (facultatif)',
        'initial_amount_help' => 'Le montant est déduit du non affecté. Laisse vide pour créer une cagnotte vide.',
        'link_to' => 'Lier à (facultatif)',
        'link_goal' => 'Objectif',
        'link_none' => 'Aucun',
        'select_goal' => 'Choisis un objectif',
        'save_pot' => 'Enregistrer la cagnotte',
        'save_changes' => 'Enregistrer les modifications',
    ],

    'fund' => [
        'title' => 'Alimenter la cagnotte',
        'heading' => 'Alimenter :name',
        'submit' => 'Alimenter la cagnotte',
        'note_placeholder' => 'ex. Épargne mensuelle',
        'available' => 'Disponible à affecter : :amount (non affecté)',
    ],

    'move' => [
        'title' => 'Déplacer des fonds',
        'heading' => 'Déplacer depuis :name',
        'to' => 'Déplacer vers',
        'select_pot' => 'Choisis une cagnotte',
        'no_others_short' => 'Aucune autre cagnotte',
        'no_others' => 'Aucune autre cagnotte dans ce compte',
        'submit' => 'Déplacer les fonds',
        'note_placeholder' => 'ex. Transfert pour les vacances',
    ],

    'withdraw' => [
        'heading' => 'Retirer de :name',
        'note_placeholder' => 'ex. Retrait',
    ],

    'available_in' => 'Disponible dans :name : :amount',

    'errors' => [
        'enter_name' => 'Saisis un nom pour cette cagnotte.',
        'select_account' => 'Choisis un compte pour cette cagnotte.',
        'amount_exceeds_unallocated' => 'Le montant dépasse le solde non affecté.',
        'amount_exceeds_unallocated_available' => 'Le montant dépasse le solde non affecté (:amount disponible).',
        'amount_exceeds_pot_balance' => 'Le montant dépasse le solde de :name (:amount disponible).',
    ],

    'toast' => [
        'pot_created' => 'Cagnotte créée.',
        'pot_updated' => 'Cagnotte mise à jour.',
        'pot_funded' => 'Cagnotte alimentée.',
        'withdrawn' => 'Retiré de la cagnotte.',
        'funds_moved' => 'Fonds déplacés.',
        'pot_archived' => 'Cagnotte archivée.',
        'pot_restored' => 'Cagnotte restaurée.',
    ],
];
