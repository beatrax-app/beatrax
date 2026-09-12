<?php

declare(strict_types=1);

return [
    'conflict' => [
        'field' => [
            'amount_minor' => 'bedrag',
            'currency' => 'valuta',
            'description' => 'omschrijving',
            'counterparty_name' => 'winkeliersnaam',
            'default' => 'waarde',
        ],
        'heading_cleaner' => 'Een e-mailbon heeft bij :field een duidelijkere waarde',
        'heading_different' => 'Een e-mailbon heeft bij :field een andere waarde',
        'title' => 'Bon en afschrift komen niet overeen.',
        'body' => ':heading (“:receipt”) dan het afschrift (“:statement”). Moet Beatrax bonnen voorrang geven bij toekomstige conflicten?',
        'use_receipt' => 'Bon gebruiken',
        'keep_statement' => 'Afschrift behouden',
        'heading_restated' => 'Een later afschrift heeft bij :field een andere waarde',
        'restated_title' => 'Je bank heeft deze transactie herzien.',
        'restated_body' => ':heading (“:incoming”) dan de al opgeslagen regel (“:stored”). Moet Beatrax de nieuwe waarde voorrang geven bij toekomstige verschillen?',
        'use_restated' => 'Nieuwe waarde gebruiken',
        'keep_stored' => 'Opgeslagen waarde behouden',
    ],
];
