<?php

declare(strict_types=1);

return [
    'page_title' => 'Pré-visualizar a importação',
    'heading' => 'Pré-visualizar a importação',
    'discard' => 'Descartar a importação',
    'confirm' => 'Confirmar a importação',
    'subtitle' => 'Revê as linhas processadas. Nada é guardado no teu livro-razão até confirmares.',

    'already_imported' => 'Este ficheiro já foi importado.',

    'already_imported_link' => 'Ver o resultado da importação',

    'expired_html' => 'A pré-visualização expirou. <a href="/imports/new" class="underline">Volta a carregar o ficheiro</a> para tentares de novo.',
    'unreadable_html' => 'Não é possível ler a pré-visualização. <a href="/imports/new" class="underline">Volta a carregar o ficheiro</a> para tentares de novo.',

    'save_name' => 'Guardar o nome',
    'account_name_label' => 'Nome da conta',
    'account_placeholder' => 'ex.: Conta poupança principal',
    'rename_aria' => 'Mudar o nome desta contraparte',

    'unknown_iban_prefix' => 'Encontrámos um IBAN desconhecido:',

    'unknown_account_prefix' => 'Encontrámos uma conta desconhecida:',
    'unknown_iban_suffix' => 'Dá um nome a esta conta.',

    'ics' => [
        'name' => 'Cartão ICS',
        'heading' => 'Dá um nome à tua conta de cartão ICS.',
        'help' => 'É a primeira vez que importas dados ICS. Dá um nome a este cartão para que apareça de forma consistente em toda a app.',
        'placeholder' => 'ex.: Cartão ICS',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Dá um nome à tua conta PayPal.',
        'help' => 'É a primeira vez que importas dados do PayPal. Dá um nome a esta carteira para que apareça de forma consistente em toda a app.',
        'placeholder' => 'ex.: PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Dá um nome à tua conta Google Play.',
        'help' => 'É a primeira vez que importas um recibo do Google Play. Dá um nome a esta conta para que apareça de forma consistente em toda a app.',
        'placeholder' => 'ex.: Google Play',
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

    'rows_shown' => 'Linhas mostradas: :shown de :total',

    'show_more' => 'Mostrar mais linhas',

    'errors' => [
        'app_locked' => 'Desbloqueie a aplicação para importar: as chaves de encriptação não podem ser usadas enquanto estiver bloqueada.',
        'archive_holds_one_message' => 'Este ficheiro é uma única mensagem de e-mail, não um arquivo de caixa de correio, por isso lido como arquivo não tem nada dentro. Carrega-o outra vez com o formato Mensagem de e-mail.',
        'email_file_is_an_archive' => 'Este ficheiro é um arquivo de caixa de correio: tem mais do que uma mensagem, e lido como uma única mensagem só levaria a primeira. Carrega-o outra vez com o formato Arquivo de caixa de correio.',
        'file_stopped_short' => 'A linha de cabeçalho correspondia, por isso o formato está certo. A leitura parou antes do fim do ficheiro. Basta uma linha ilegível, ou um ficheiro demasiado grande para este dispositivo. Experimenta um período mais curto.',
        'file_unreadable' => 'Não foi possível ler este ficheiro.',
        'file_unreadable_detail' => 'A aplicação não conseguiu ler este ficheiro (:code). Os detalhes completos estão no registo da aplicação; indique este código se comunicar um problema.',
        'iban_not_in_preview' => 'Este IBAN não faz parte da pré-visualização atual.',
        'message_unreadable' => 'Não foi possível ler esta mensagem, pelo que foi ignorada.',
        'not_an_email_file' => 'Este ficheiro não é uma mensagem de e-mail nem um arquivo de caixa de correio, por isso não há nada nele para ler como recibo. Escolhe o tipo de importação e o formato que correspondem ao teu ficheiro.',
        'pdf_has_no_text_layer' => 'Este PDF não tem texto — é uma digitalização ou uma foto de um extrato, por isso não há nada para ler. Descarrega o extrato em si no teu banco, ou usa antes uma exportação CSV.',
        'pdf_password_protected' => 'Este PDF está protegido por palavra-passe, por isso nenhum leitor o consegue abrir. Guarda uma cópia sem proteção a partir do teu visualizador de PDF e importa essa.',
        'pdf_reader_unavailable' => 'Esta versão da aplicação não tem qualquer leitor de PDF, por isso não é possível abrir aqui um extrato em PDF. Importa este ficheiro noutro dispositivo, ou usa antes uma exportação CSV do teu banco.',
        'row_belongs_to_another_statement' => 'Esta linha pertence a uma transação noutro ficheiro de extrato. Importe também esse extrato — os dois são lidos em conjunto.',
        'row_unreadable' => 'Não foi possível ler esta linha.',
        'row_unreadable_detail' => 'A aplicação não conseguiu ler esta linha (:code). Os detalhes completos estão no registo da aplicação; indique este código se comunicar um problema.',
        'unknown_account' => 'Esta linha pertence a uma conta a que ainda não deste nome.',
    ],

    'refused' => [
        'accounts_to_name' => 'Este ficheiro espera que dês nome à conta a que pertencem as suas linhas.',
        'file_did_not_read_in_full' => 'Não foi possível ler este ficheiro até ao fim.',
        'nothing_importable' => 'Não há nada neste ficheiro que possa ser importado.',
        'preview_expired' => 'A pré-visualização deste ficheiro é demasiado antiga para guardar agora. Volta a carregá-lo.',
    ],

    'receipts' => [
        'heading' => 'Este ficheiro foi lido como e-mail',
        'saved' => 'O que trazia está listado abaixo, e cada mensagem foi guardada.',
        'none_imported' => 'Nada disto se tornou uma transação, por isso não foi acrescentado nada ao teu livro-razão.',
        'shown' => 'Mensagens mostradas: :shown de :total',
        'no_subject' => 'Sem assunto',

        'state' => [
            'read' => 'Lida como pagamento — confirma esta importação para a acrescentar ao teu livro-razão.',
            'not_a_payment' => 'Não é um pagamento. Esta mensagem anuncia algo em vez de confirmar um pagamento.',
            'unreadable' => 'Guardada. A aplicação lê recibos deste remetente, mas não encontrou valor, comerciante nem referência nesta mensagem.',
            'unknown_sender' => 'Guardada. A aplicação não lê recibos deste remetente, por isso não retirou nada da mensagem.',
        ],
    ],

    'failed' => [
        'heading' => 'Não foi possível ler este ficheiro',
        'no_rows' => 'Não foram encontradas transações neste ficheiro, por isso não há nada para importar.',
        'nothing_read' => 'Nada neste ficheiro pôde ser lido como transação, por isso não há nada para importar.',
        'every_row' => 'Nenhuma linha deste ficheiro pôde ser lida, por isso não há nada para importar. Cada uma está listada abaixo com o motivo.',
        'likely_cause' => 'Normalmente a linha de cabeçalho não corresponde à origem que escolheste. Verifica o banco e o formato no ecrã de envio, ou transfere o extrato do teu banco outra vez.',
        'truncated_heading' => 'Só foi possível ler parte deste ficheiro',
        'truncated' => 'A leitura parou a meio do ficheiro. Este ficheiro não pode ser importado: guardar apenas a parte lida deixaria o resto do período em falta, sem nada que o assinale.',
        'truncated_action' => 'Carregue o ficheiro novamente ou transfira uma nova cópia do extrato do seu banco.',
        'some_rows' => 'Algumas linhas não puderam ser lidas. Estão marcadas abaixo e serão ignoradas; confirmar importa as restantes.',
        'detail_label' => 'O que o analisador reportou:',
        'rows_read_label' => 'Linhas lidas',
        'rows_skipped_label' => 'Linhas ignoradas',
    ],
];
