<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Rever e confirmar',
    'h1' => 'Revê tudo o que encontrámos',

    'lede_counts' => ':transactions em :sources.',
    'source' => ':count origem|:count origens',
    'lede_confirm' => 'Verifica os teus saldos iniciais e depois confirma.',

    'empty' => 'Ainda não há nada para rever. Larga um extrato nos passos anteriores para veres aqui as tuas transações.',

    'sb_eyebrow_label' => '🧮 SALDOS INICIAIS ·',
    'account_detected' => ':count CONTA DETETADA|:count CONTAS DETETADAS',
    'sb_lede' => 'Detetámos o saldo inicial de cada conta. Confirma ou edita antes de gravarmos.',

    'txn' => ':count transação|:count transações',
    'to_commit' => 'por confirmar ·',
    'already_imported' => ':count já importada|:count já importadas',
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
        'row' => ':count LINHA|:count LINHAS',
        'badge_ready' => '✓ PRONTO',
        'badge_empty' => 'VAZIO',
        'badge_error' => 'CARREGAR DE NOVO',
        'error_body' => 'Não conseguimos ler todos os ficheiros desta origem. Experimenta outro ficheiro →',
        'left_out' => 'Um ficheiro aqui ficou de fora, por isso só o resto será guardado: :reason|:count ficheiros aqui ficaram de fora, por isso só o resto será guardado: :reason',
        'rows_skipped' => 'Algumas linhas aqui não puderam ser lidas e serão ignoradas: :reason',
        'empty_body' => 'Este extrato está vazio.',
        'col_date' => 'Data',
        'col_type' => 'Tipo',
        'col_counterparty' => 'Contraparte',
        'col_amount' => 'Montante',
        'load_more' => 'Carregar mais (:remaining restantes)',
        'rows_shown' => ':count linha mostrada|:count linhas mostradas',
    ],
];
