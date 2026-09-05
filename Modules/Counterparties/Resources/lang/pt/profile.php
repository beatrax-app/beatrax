<?php

declare(strict_types=1);

return [
    'page_title' => 'Contraparte',
    'fallback_account' => 'Conta',
    'fallback_counterparty' => 'Contraparte',

    'edit_display_name' => 'Editar o nome apresentado',

    'hero_net_received' => 'Líquido recebido',
    'hero_12mo_total' => 'Total de 12 meses',
    'hero_transactions' => 'Transações',
    'hero_first_seen' => 'Primeira vez',

    'tabs' => [
        'overview' => 'Resumo',
        'transactions' => 'Transações',
        'chains' => 'Cadeias',
        'aliases' => 'Aliases',
        'transfers' => 'Transferências',
        'entries' => 'Lançamentos',
        'payments' => 'Pagamentos',
        'tax_years' => 'Anos fiscais',
    ],

    'tablist_aria' => 'Secções da contraparte',

    'tab_note_personal' => '— não há cadeias de financiamento para contactos pessoais',
    'tab_note_bank' => '— uma contraparte de comissões bancárias não gera cadeias de financiamento',
    'tab_note_bank_institution' => '— não há cadeias de financiamento para contrapartes institucionais',
    'tab_note_government' => '— não há cadeias de financiamento para contrapartes do Estado',

    'recent_activity' => 'Atividade recente',
    'recurring' => 'Recorrente',
    'uncategorized' => 'Sem categoria',
    'no_recent_transactions' => 'Ainda não há transações registadas para esta contraparte.',
    'see_all' => 'Ver todas as :count →',

    'bank' => [
        'fees_heading' => 'Comissões bancárias por categoria',
        'activity_heading' => 'Atividade por categoria',
        'no_fees' => 'Ainda não há comissões registadas nesta contraparte.',
    ],

    'government' => [
        'intro' => 'Repartição anual por todos os anos com atividade. O ano em curso está destacado.',
        'no_payments' => 'Ainda não há pagamentos registados para esta contraparte.',
    ],

    'merchant' => [
        'categories' => 'Categorias',

        'categories_empty_html' => 'Ainda não há categorias — as transações sem categoria aparecem em <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Categorização</a>.',
        'no_recurring' => 'Não foram detetados padrões recorrentes.',
        'per_month_suffix' => '/mês',
        'funding_chain' => 'Cadeia de financiamento',
        'no_funding_chain' => 'Ainda não foi detetada nenhuma cadeia de financiamento. Para resolver cadeias de financiamento são necessárias importações de dados da ASN + PayPal.',
        'open_chains' => 'Abrir a revisão de Cadeias →',
    ],

    'personal' => [
        'contact' => 'Contacto',
        'add_tag' => '+ Adicionar etiqueta',
        'no_recurring' => 'Não foi detetada recorrência — as transferências pessoais raramente seguem uma periodicidade rígida; até uma renda partilhada regular pode mudar de data.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Esta contraparte ainda não está identificada',
        'not_labelled_body' => 'Identificar as desconhecidas ajuda o painel a mostrar totais mensais e cadeias de financiamento corretos.',
        'label_cta' => 'Identificar esta contraparte',
    ],

    'support' => [
        'contact_help' => 'Contacto e ajuda',
        'sign_in_apply' => 'Iniciar sessão · requerer',
        'your_rights' => 'Os teus direitos · reclamar',
        'cancel' => 'Cancelar',
        'help_support' => 'Ajuda e apoio',
        'cheaper_plan' => 'Plano mais barato',
        'aria_gov' => 'Obter ajuda',
        'aria_merchant' => 'Apoio e cancelamento',
        'heading_gov' => 'Obter ajuda',
        'heading_merchant' => 'Apoio e cancelamento',
        'cancel_by_email' => 'Cancelar por e-mail',
        'withheld' => 'ligação não oferecida',
    ],
];
