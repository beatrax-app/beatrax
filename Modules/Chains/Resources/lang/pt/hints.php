<?php

declare(strict_types=1);

return [
    'page_title' => 'Pistas de cadeias',
    'heading' => 'Pistas',
    'back_to_review' => '← Voltar à fila de revisão',
    'subtitle' => 'Candidatos que um comparador emitiu sem uma contraparte correspondente. Uma dica de liquidação desaparece sozinha assim que chegam os débitos em falta; as restantes ficam até as dispensares aqui.',

    'empty_heading' => 'Não há pistas para triar',
    'empty_body' => 'Quando um comparador encontrar uma cadeia que não conseguiu resolver automaticamente, ela aparece aqui.',

    'no_counterparty' => '(sem contraparte)',
    'unknown_account' => '(conta desconhecida)',

    'dismiss' => 'Dispensar',
    'dismiss_aria' => 'Dispensar a pista :id',
    'dismissed' => 'Pista dispensada.',

    'kind' => [
        'ics_bulk_settle' => 'Liquidação iDEAL agrupada (fora da tolerância)',
        'funded_by_card_hint' => 'Financiado por cartão (pista)',
        'refund_of_hint' => 'Reembolso (pista)',
    ],

    'evidence' => [
        'tolerance' => 'Tolerância: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'dentro da margem fixa',
            'percent_2' => 'dentro da margem percentual',
            'exceeded' => 'fora da margem',
            'refund_after_close' => 'reembolso após o fecho',
        ],
        'delta_overpaid' => 'Pago a mais: :amount',
        'delta_underpaid' => 'Faltam :amount',
        'delta_balanced' => 'Fecha exatamente',
        'covered' => 'Transações cobertas: :count',
        'statement' => 'Extrato do cartão n.º :id',
        'card_last4' => 'Cartão terminado em :last4',
        'original_reference' => 'Referência da encomenda original: :reference',
    ],
];
