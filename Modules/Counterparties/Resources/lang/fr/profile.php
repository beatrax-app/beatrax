<?php

declare(strict_types=1);

return [
    'page_title' => 'Tiers',
    'fallback_account' => 'Compte',
    'fallback_counterparty' => 'Tiers',

    'edit_display_name' => 'Modifier le nom affiché',

    'hero_net_received' => 'Net reçu',
    'hero_12mo_total' => 'Total sur 12 mois',
    'hero_transactions' => 'Transactions',
    'hero_first_seen' => 'Première apparition',

    'tabs' => [
        'overview' => 'Aperçu',
        'transactions' => 'Transactions',
        'chains' => 'Chaînes',
        'aliases' => 'Alias',
        'transfers' => 'Virements',
        'entries' => 'Écritures',
        'payments' => 'Paiements',
        'tax_years' => 'Années fiscales',
    ],

    'tablist_aria' => 'Sections du tiers',

    'tab_note_personal' => '— pas de chaînes de financement pour les contacts personnels',
    'tab_note_bank' => '— un tiers de frais bancaires ne génère pas de chaînes de financement',
    'tab_note_government' => '— pas de chaînes de financement pour les tiers administratifs',

    'recent_activity' => 'Activité récente',
    'recurring' => 'Récurrent',
    'uncategorized' => 'Non catégorisé',
    'no_recent_transactions' => 'Aucune transaction enregistrée pour ce tiers pour l\'instant.',
    'see_all' => 'Voir les :count →',

    'bank' => [
        'fees_heading' => 'Frais bancaires par catégorie',
        'no_fees' => 'Aucun frais enregistré sur ce tiers pour l\'instant.',
    ],

    'government' => [
        'intro' => 'Répartition annuelle sur toutes les années avec activité. L\'année en cours est mise en avant.',
        'no_payments' => 'Aucun paiement enregistré pour ce tiers pour l\'instant.',
    ],

    'merchant' => [
        'categories' => 'Catégories',

        'categories_empty_html' => 'Aucune catégorie pour l\'instant — les transactions non catégorisées apparaissent dans <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Catégorisation</a>.',
        'no_recurring' => 'Aucun schéma récurrent détecté.',
        'per_month_suffix' => '/mois',
        'funding_chain' => 'Chaîne de financement',
        'no_funding_chain' => 'Aucune chaîne de financement détectée pour l\'instant. La résolution des chaînes de financement nécessite l\'import de données ASN et PayPal.',
        'open_chains' => 'Ouvrir la vérification des chaînes →',
    ],

    'personal' => [
        'contact' => 'Contact',
        'add_tag' => '+ Ajouter une étiquette',
        'no_recurring' => 'Aucune récurrence détectée — les virements personnels suivent rarement un rythme strict ; même un partage de loyer régulier peut changer de date.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Ce tiers n\'est pas encore étiqueté',
        'not_labelled_body' => 'Étiqueter les inconnus aide le tableau de bord à afficher des totaux mensuels et des chaînes de financement exacts.',
        'label_cta' => 'Étiqueter ce tiers',
    ],

    'support' => [
        'contact_help' => 'Contact et aide',
        'sign_in_apply' => 'Se connecter · faire une demande',
        'your_rights' => 'Tes droits · opposition',
        'cancel' => 'Résilier',
        'help_support' => 'Aide et assistance',
        'cheaper_plan' => 'Forfait moins cher',
        'aria_gov' => 'Obtenir de l\'aide',
        'aria_merchant' => 'Assistance et résiliation',
        'heading_gov' => 'Obtenir de l\'aide',
        'heading_merchant' => 'Assistance et résiliation',
        'cancel_by_email' => 'Résilier par e-mail',
    ],
];
