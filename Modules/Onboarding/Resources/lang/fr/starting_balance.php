<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 SOLDE DE DÉPART',
    'confirmed_aria' => 'confirmé',
    'on_date' => 'le :date',

    'detected_h3' => 'Nous avons détecté que ton :label a démarré à',
    'confirm' => 'Confirmer',
    'edit' => 'Modifier',

    'conflict_h3' => 'Nous avons vu deux valeurs pour ce compte — laquelle est la bonne ?',
    'conflict_legend' => 'Choisis un solde de départ',
    'conflict_from' => 'Depuis :source :',
    'conflict_helper' => 'Par défaut, nous prenons la date la plus ancienne. Choisis la bonne ou modifie manuellement.',
    'edit_manually' => 'Modifier manuellement',

    'editing_h3' => 'Modifie le solde de départ de ton :label',
    'input_label' => 'SOLDE DE DÉPART',
    'minor_units' => '(en centimes)',
    'on_date_label' => 'À LA DATE DU',
    'cancel' => 'Annuler',
    'save' => 'Enregistrer',

    'change' => 'Changer',

    'manual_h3' => 'Saisis manuellement le solde de départ de ton :label',
    'manual_lede' => 'Nous n\'avons pas pu détecter automatiquement un solde de départ pour ce compte. Saisis-en un manuellement ou passe cette étape.',

    'unknown_state' => 'État de carte inconnu. Recharge l\'assistant.',

    'errors' => [
        'account_not_set' => 'Compte non défini. Recharge l\'assistant.',
        'invalid_amount' => 'Saisis un montant valide.',
        'amount_range' => 'Saisis un montant compris entre :min et :max.',
        'pick_date' => 'Choisis une date.',
        'pick_valid_date' => 'Choisis une date valide.',
        'future_date' => 'La date du solde de départ ne peut pas être dans le futur.',
        'date_warning' => 'C\'est postérieur à ta première transaction importée (:date). Ton tableau de bord peut afficher des transactions antérieures à cette date.',
    ],
];
