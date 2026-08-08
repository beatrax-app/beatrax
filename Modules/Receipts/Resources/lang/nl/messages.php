<?php

declare(strict_types=1);

return [
    'wizard' => [
        'intro' => 'Sleep een e-mailbericht (.eml) of een mailboxarchief (.mbox) hierheen. De matcher herkent PayPal-bonnen en toont ze als canonieke transacties; niet-herkende afzenders blijven in het auditlogboek voor triage.',
    ],

    'conflict' => [
        'field' => [
            'amount_minor' => 'bedrag',
            'currency' => 'valuta',
            'description' => 'omschrijving',
            'counterparty_name' => 'winkeliersnaam',
            'default' => 'waarde',
        ],
        'heading_cleaner' => 'Een e-mailbon heeft een duidelijkere :field',
        'heading_different' => 'Een e-mailbon vermeldt een andere :field',
        'title' => 'Bon en afschrift komen niet overeen.',
        'body' => ':heading (“:receipt”) dan het afschrift (“:statement”). Moet Beatrax bonnen voorrang geven bij toekomstige conflicten?',
        'use_receipt' => 'Bon gebruiken',
        'keep_statement' => 'Afschrift behouden',
    ],
];
