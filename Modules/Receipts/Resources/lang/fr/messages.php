<?php

declare(strict_types=1);

return [
    'wizard' => [
        'intro' => 'Dépose un message e-mail (.eml) ou une archive de boîte aux lettres (.mbox). Le moteur de correspondance reconnaît les reçus PayPal et les fait remonter comme transactions canoniques ; les expéditeurs non reconnus restent dans le journal d\'audit pour triage.',
    ],

    'conflict' => [
        'field' => [
            'amount_minor' => 'le montant',
            'currency' => 'la devise',
            'description' => 'le libellé',
            'counterparty_name' => 'le nom du commerçant',
            'default' => 'la valeur',
        ],
        'heading_cleaner' => 'Un reçu par e-mail est plus clair sur :field',
        'heading_different' => 'Un reçu par e-mail enregistre :field autrement',
        'title' => 'Le reçu et le relevé divergent.',
        'body' => ':heading (« :receipt ») que le relevé (« :statement »). Beatrax doit-il privilégier les reçus lors des prochains conflits ?',
        'use_receipt' => 'Utiliser le reçu',
        'keep_statement' => 'Conserver le relevé',
    ],
];
