<?php

declare(strict_types=1);

return [
    'eyebrow' => 'A tua conta PayPal',
    'h1' => 'Liga a tua conta PayPal',

    'lede_html' => 'Larga aqui a exportação de movimentos do PayPal — uma linha por transação, não o resumo de saldo. O PayPal dá nome aos relatórios no idioma da tua conta e, para já, lemos o par neerlandês: <em lang="nl">Rapport Transactiegegevens</em>, não <span lang="nl">Saldorapport</span>. Se o teu sair noutro idioma, muda o PayPal para neerlandês antes de o transferires.',

    'format_group_aria' => 'O PayPal exporta apenas em CSV',
    'got_it_as' => 'Obtive-o como:',
    'badge_only_format' => 'único formato',

    'mini' => [
        'login_label' => 'Inicia sessão',
        'custom_label' => 'Extratos personalizados',
        'range_label' => 'Escolhe um período',
        'range_sub' => 'Últimos 12 meses',
        'download_label' => 'Transfere em CSV',
    ],

    'drop_lead' => 'Larga aqui a tua exportação de movimentos',
    'browse_file' => 'ou procura um ficheiro',

    'file_ready' => '· ✓ pronto',

    'skip' => 'Ignorar este passo',
    'continue' => 'Continuar →',

    'errors' => [
        'required' => 'Larga primeiro na caixa a exportação de movimentos do PayPal.',
        'max' => 'Esse ficheiro é demasiado grande. Uma exportação de movimentos do PayPal costuma ficar bem abaixo de 10 MB.',
        'extensions' => 'Esse ficheiro não parece um CSV do PayPal. Transfere a exportação de movimentos — uma linha por transação, não o resumo de saldo — em CSV.',
        'unreadable' => 'Não foi possível ler este ficheiro. O erro completo está em /dev/logs.',
    ],
];
