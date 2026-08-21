<?php

declare(strict_types=1);

return [
    'page_title' => 'Pré-visualizar a importação',
    'heading' => 'Pré-visualizar a importação',
    'discard' => 'Descartar a importação',
    'confirm' => 'Confirmar a importação',
    'subtitle' => 'Revê as linhas processadas. Nada é guardado no teu livro-razão até confirmares.',

    'expired_html' => 'A pré-visualização expirou. <a href="/imports/new" class="underline">Volta a carregar o ficheiro</a> para tentares de novo.',

    'save_name' => 'Guardar o nome',
    'account_name_label' => 'Nome da conta',
    'account_placeholder' => 'ex.: Conta poupança principal',
    'rename_aria' => 'Mudar o nome desta contraparte',

    'unknown_iban_prefix' => 'Encontrámos um IBAN desconhecido:',
    'unknown_iban_suffix' => 'Dá um nome a esta conta.',

    'ics' => [
        'heading' => 'Dá um nome à tua conta de cartão ICS.',
        'help' => 'É a primeira vez que importas dados ICS. Dá um nome a este cartão para que apareça de forma consistente em toda a app.',
        'placeholder' => 'ex.: Cartão ICS',
    ],

    'paypal' => [
        'heading' => 'Dá um nome à tua conta PayPal.',
        'help' => 'É a primeira vez que importas dados do PayPal. Dá um nome a esta carteira para que apareça de forma consistente em toda a app.',
        'placeholder' => 'ex.: PayPal',
    ],

    'col_date' => 'Data',
    'col_funding_source' => 'Fonte de financiamento',
    'col_counterparty' => 'Contraparte',
    'col_amount' => 'Montante',
    'col_status' => 'Estado',

    'status' => [
        'new' => 'Nova',
        'new_title' => 'Vai ser adicionada ao teu livro-razão.',
        'duplicate' => 'Duplicada',
        'duplicate_title' => 'Já foi importada — vai ser ignorada.',
        'enriched' => 'Enriquecida',
        'enriched_title' => 'A linha existente vai ser atualizada com uma referência de origem mais fiável.',
        'error' => 'Erro',
    ],

    'chain' => [
        'heading' => 'A resolver cadeias…',
        'pending' => 'Em fila. O resolvedor de cadeias começa dentro de momentos.',
        'running' => 'A ligar cadeias de financiamento e a decompor liquidações de extrato.',
        'failed_prefix' => 'A resolução de cadeias falhou:',
        'unknown_error' => 'ocorreu um erro desconhecido',
        'open_horizon' => 'Abre o Horizon',
        'failed_suffix' => 'para repetir ou inspecionar.',
    ],

    'errors' => [
        'app_locked' => 'Desbloqueie a aplicação para importar: a chave do comerciante não pode ser calculada enquanto estiver bloqueada.',
        'file_unreadable' => 'Não foi possível ler este ficheiro.',
        'iban_not_in_preview' => 'Este IBAN não faz parte da pré-visualização atual.',
        'row_unreadable' => 'Não foi possível ler esta linha.',
        'unknown_account' => 'Esta linha pertence a uma conta a que ainda não deste nome.',
    ],

    'failed' => [
        'heading' => 'Não foi possível ler este ficheiro',
        'no_rows' => 'Não foram encontradas transações neste ficheiro, por isso não há nada para importar.',
        'nothing_read' => 'Nada neste ficheiro pôde ser lido como transação, por isso não há nada para importar.',
        'every_row' => 'Nenhuma linha deste ficheiro pôde ser lida, por isso não há nada para importar. Cada uma está listada abaixo com o motivo.',
        'likely_cause' => 'Normalmente a linha de cabeçalho não corresponde à origem que escolheste. Verifica o banco e o formato no ecrã de envio, ou transfere o extrato do teu banco outra vez.',
        'truncated_heading' => 'Só foi possível ler parte deste ficheiro',
        'truncated' => 'A leitura parou a meio do ficheiro. Tudo o que vem depois não foi lido e não será importado.',
        'some_rows' => 'Algumas linhas não puderam ser lidas. Estão marcadas abaixo e serão ignoradas; confirmar importa as restantes.',
        'detail_label' => 'O que o analisador reportou:',
        'rows_read_label' => 'Linhas lidas',
        'rows_skipped_label' => 'Linhas ignoradas',
    ],
];
