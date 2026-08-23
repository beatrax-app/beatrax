<?php

declare(strict_types=1);

return [
    'help_paypal' => 'Les exports PayPal ne contiennent pas de lignes de solde : renseigne-le manuellement.',
    'help_asn' => 'Calé automatiquement sur ton dernier relevé. Ne le modifie que si tu sais que le solde réel diffère.',
    'help_default' => 'Ne le modifie que si tu sais que le solde réel actuel diffère de celui que Beatrax calcule.',

    'legend' => 'Solde d\'ouverture prévisionnel pour :name',
    'opening_label' => 'Solde d\'ouverture',
    'opening_placeholder' => 'ex. 1.250,00',
    'as_of_label' => 'Solde d\'ouverture à la date du',
    'as_of_help' => 'La date à laquelle le montant ci-dessus est exact.',

    'divergence' => 'C\'est plus de 500 € d\'écart avec le solde que Beatrax calcule à partir de tes transactions importées. Tu confirmes ?',
    'use_beatrax' => 'Utiliser le chiffre de Beatrax',
    'use_mine' => 'Utiliser mon chiffre',

    'save' => 'Enregistrer le solde d\'ouverture',
    'remove' => 'Supprimer le solde d\'ouverture',
    'saved' => 'Enregistré.',
    'removed' => 'Supprimé.',

    'toast' => [
        'updated' => 'Solde d\'ouverture mis à jour.',
        'removed' => 'Solde d\'ouverture supprimé.',
    ],

    'errors' => [
        'invalid_number' => 'Le solde d\'ouverture doit être un nombre valide.',
        'date_required' => 'Choisis la date à laquelle ce solde d\'ouverture s\'applique.',
        'date_invalid' => 'La date du solde d\'ouverture doit être une date ISO valide (YYYY-MM-DD).',
        'date_future' => 'La date du solde d\'ouverture ne peut pas être dans le futur.',
    ],
];
