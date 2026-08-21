<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Rever e confirmar',
    'h1' => 'Revê tudo o que encontrámos',

    'lede_across' => 'transações em',
    'source' => 'origem|origens',
    'lede_confirm' => 'Verifica os teus saldos iniciais e depois confirma.',

    'empty' => 'Ainda não há nada para rever. Larga um extrato nos passos anteriores para veres aqui as tuas transações.',

    'sb_eyebrow_label' => '🧮 SALDOS INICIAIS ·',
    'account_detected' => 'CONTA DETETADA|CONTAS DETETADAS',
    'sb_lede' => 'Detetámos o saldo inicial de cada conta. Confirma ou edita antes de gravarmos.',

    'txn' => 'transação|transações',
    'to_commit' => 'por confirmar ·',
    'already_imported' => 'já importadas',
    'commit_committing' => 'A confirmar…',
    'commit_count' => 'Confirmar tudo (:count transação) →|Confirmar tudo (:count transações) →',
    'commit_empty' => 'Confirmar tudo (—) →',
    'skip' => 'Ignorar por agora',

    'errors' => [
        'nothing_to_commit' => 'Não há nada para confirmar.',
        'commit_failed' => 'Não conseguimos confirmar os teus extratos. Nada foi alterado — tenta novamente.',
    ],

    'section' => [
        'from_prefix' => 'DE ',
        'from_bank' => 'DO TEU EXTRATO BANCÁRIO',
        'from_ics' => 'DOS TEUS EXTRATOS DE CARTÃO ICS',
        'from_paypal' => 'DO PAYPAL',
        'row' => 'LINHA|LINHAS',
        'badge_ready' => '✓ PRONTO',
        'badge_empty' => 'VAZIO',
        'badge_error' => 'CARREGAR DE NOVO',
        'badge_filtered' => 'JÁ IMPORTADO',
        'error_body' => 'Não conseguimos ler todos os ficheiros desta origem. Experimenta outro ficheiro →',
        'partial_body' => 'Parte deste ficheiro não pôde ser lida e foi deixada de fora: :reason',
        'empty_body' => 'Este extrato está vazio.',
        'filtered_body' => 'Este extrato já foi importado noutro sítio — deixámo-lo de fora.',
        'col_date' => 'Data',
        'col_type' => 'Tipo',
        'col_counterparty' => 'Contraparte',
        'col_amount' => 'Montante',
        'load_more' => 'Carregar mais (:remaining restantes)',
        'rows_shown' => ':count linha mostrada|:count linhas mostradas',
    ],
];
