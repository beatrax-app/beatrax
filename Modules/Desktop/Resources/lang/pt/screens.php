<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Bem-vindo',
        'heading' => 'Bem-vindo ao Beatrax',
        'subtitle' => 'O teu painel de finanças, só local, está pronto. Cria a tua primeira conta para começar.',
        'get_started' => 'Começar',
    ],

    'setup' => [
        'page_title' => 'A preparar…',
        'pending_heading' => 'A preparar…',
        'pending_body' => 'O Beatrax está a preparar os teus dados. Demora só um momento.',
        'failed_body' => 'A preparação não conseguiu terminar. Reinicia o Beatrax; se continuar a falhar, o motivo está no registo.',
        'ready_heading' => 'Pronto',
        'ready_body' => 'Preparação concluída. A continuar…',
    ],

    'staging' => [
        'page_title' => 'Ficheiro recebido',
        'heading_prefix' => 'Ficheiro recebido: ',
        'button_label' => 'Iniciar a importação',
        'csv_subtitle' => 'Uma exportação de um banco ou do PayPal — inicia a importação para pré-visualizar e confirmar.',
        'eml_subtitle' => 'Um recibo por e-mail — inicia a importação para o anexar à respetiva transação.',
        'empty_heading' => 'Não conseguimos abrir esse ficheiro',
        'empty_body' => 'O Beatrax não conseguiu ler o ficheiro que abriste. Experimenta importá-lo a partir da página Importações.',
        'open_imports' => 'Abrir Importações',
    ],

    'close' => [
        'title' => 'Manter o Beatrax a funcionar?',
        'body' => 'Ao fechar a janela podes sair completamente do Beatrax ou mantê-lo a correr discretamente na barra de menus, para que as análises de e-mail agendadas continuem.',
        'button_quit' => 'Sair do Beatrax',
        'button_keep_in_tray' => 'Manter a correr na bandeja',
        'checkbox_remember' => 'Memorizar a minha escolha',
    ],
];
