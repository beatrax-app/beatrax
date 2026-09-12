<?php

declare(strict_types=1);

return [
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
        'heading_restated' => 'Un relevé ultérieur enregistre :field autrement',
        'restated_title' => 'La banque a corrigé cette opération.',
        'restated_body' => ':heading (« :incoming ») que la ligne déjà enregistrée (« :stored »). Beatrax doit-il privilégier la nouvelle valeur lors des prochaines divergences ?',
        'use_restated' => 'Utiliser la nouvelle valeur',
        'keep_stored' => 'Conserver la valeur enregistrée',
    ],
];
