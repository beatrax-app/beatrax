<?php

declare(strict_types=1);

return [
    'page_title' => 'Carregar extrato',
    'heading' => 'Carregar extrato',
    'migrate_prompt' => 'Vens de outra app de orçamentos?',
    'migrate_link' => 'Importar do YNAB ou Actual',
    'subtitle' => 'Larga aqui um extrato em CSV, CAMT.053, MT940 ou PDF, ou um ficheiro de recibo de e-mail.',
    'mime_hint' => 'Ficheiros suportados: CSV bancário, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF de extrato de cartão, mensagem de e-mail (.eml) ou arquivo de caixa de correio (.mbox).',

    'type_label' => 'Tipo de importação',

    'types' => [
        'csv' => 'Ficheiro CSV',
        'camt053' => 'Extrato CAMT.053 (XML)',
        'mt940' => 'Extrato MT940',
        'pdf' => 'Extrato de cartão (PDF)',
        'email' => 'Ficheiro de recibo de e-mail',
    ],

    'format_label' => 'Formato',

    'format_from_file' => 'O formato foi definido como :format para corresponder ao ficheiro que escolheste. Muda-o se não estiver certo.',
    'file_label' => 'Ficheiro',
    'submit' => 'Carregar extrato',

    'formats' => [
        'activity_download' => 'Transferência de atividade (CSV)',
        'email_message' => 'Mensagem de e-mail (.eml)',
        'mailbox_archive' => 'Arquivo de caixa de correio (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Esse ficheiro é demasiado grande. Larga aqui uma exportação de extrato dentro do limite de tamanho do formato escolhido.',
        'file_extensions' => 'Esse ficheiro não parece uma exportação de extrato suportada. Larga aqui um CSV bancário, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, um PDF de extrato de cartão, uma mensagem de e-mail (.eml) ou um arquivo de caixa de correio (.mbox).',
        'type_format' => 'O valor de :attribute não é válido para o tipo de importação :type.',
        'process_failed' => 'Não foi possível processar este ficheiro (:class). O erro completo está em /dev/logs.',
    ],
];
