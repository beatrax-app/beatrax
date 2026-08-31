<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Tipo de contraparte: :type',
        'merchant' => 'Comerciante',
        'personal' => 'Pessoal',
        'bank' => 'Banco',
        'government' => 'Estado',
        'self' => 'Própria',
        'unknown' => 'Desconhecida',
    ],

    'filter_chips' => [
        'aria' => 'Filtrar por tipo',
        'all' => 'Todas',
        'merchant' => 'Comerciantes',
        'personal' => 'Pessoais',
        'bank' => 'Bancos',
        'government' => 'Estado',
        'self' => 'Próprias',
        'unknown' => 'Desconhecidas',
    ],

    'default_name' => [
        'bank_fee' => 'Comissão bancária',
    ],

    'cp_card' => [
        'aria' => 'Contraparte: :name',
        'recent_aria' => 'Atividade recente',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Cadeia de financiamento: ',
        'join' => ' para ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN oculto — clica em Mostrar IBAN para o ver',
        'show' => 'Mostrar IBAN',
        'hide' => 'Ocultar IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Aviso de privacidade para contactos pessoais',
        'body' => '🔒 Este é um contacto pessoal. O IBAN e os dados pessoais estão ocultos por predefinição e nunca são partilhados nas exportações.',
    ],

    'self_stub' => [
        'aria' => 'Não é uma contraparte real',
        'heading' => 'Isto não é realmente uma contraparte',

        'body_rest_html' => ' aparece aqui porque surge nas tuas transações como o troço de financiamento entre contas. Mas é <strong>a tua própria conta</strong>, não alguém com quem transacionas.',
        'body2' => 'Abre a vista da conta para veres o saldo, os extratos e o histórico completo de transações.',
        'open_cta' => 'Abrir a vista de conta de :name →',
        'hide_cta' => 'Ocultar desta lista',
        'recent_legs' => 'Troços recentes entre contas',
    ],
];
