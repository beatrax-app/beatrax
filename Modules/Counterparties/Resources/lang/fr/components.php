<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Type de tiers : :type',
        'merchant' => 'Commerçant',
        'personal' => 'Particulier',
        'bank' => 'Banque',
        'government' => 'Administration',
        'self' => 'Moi-même',
        'unknown' => 'Inconnu',
    ],

    'filter_chips' => [
        'aria' => 'Filtrer par type',
        'all' => 'Tous',
        'merchant' => 'Commerçants',
        'personal' => 'Particuliers',
        'bank' => 'Banques',
        'government' => 'Administrations',
        'self' => 'Moi-même',
        'unknown' => 'Inconnus',
    ],

    'default_name' => [
        'bank_fee' => 'Frais bancaires',
    ],

    'cp_card' => [
        'aria' => 'Tiers : :name',
        'recent_aria' => 'Activité récente',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Chaîne de financement : ',
        'join' => ' vers ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN masqué — clique sur Afficher l\'IBAN pour le révéler',
        // i18n-review: fr · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN masqué — touche sur Afficher l\'IBAN pour le révéler',
        'show' => 'Afficher l\'IBAN',
        'hide' => 'Masquer l\'IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Avis de confidentialité pour un contact personnel',
        'body' => '🔒 Ce contact est personnel. L\'IBAN et les informations personnelles sont masqués par défaut et ne sont jamais inclus dans les exports.',
    ],

    'self_stub' => [
        'aria' => 'Pas un vrai tiers',
        'heading' => 'Ce n\'est pas vraiment un tiers',

        'body_rest_html' => ' apparaît ici parce que ce nom revient dans tes transactions comme maillon de financement entre comptes. Mais il s\'agit de <strong>ton propre compte</strong>, pas de quelqu\'un avec qui tu fais des transactions.',
        'body2' => 'Ouvre la vue du compte pour le solde, les relevés et l\'historique complet des transactions.',
        'open_cta' => 'Ouvrir la vue du compte :name →',
        'hide_cta' => 'Masquer de cette liste',
        'recent_legs' => 'Maillons inter-comptes récents',
    ],
];
