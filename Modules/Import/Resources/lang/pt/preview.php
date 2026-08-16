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
        'iban_not_in_preview' => 'Este IBAN não faz parte da pré-visualização atual.',
    ],
];
