<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Apresentação',
        'money' => 'Dinheiro',
        'insights' => 'Análises e alertas',
        'security' => 'Segurança e dispositivos',
        'data' => 'Importações e dados',
        'app' => 'Aplicação',
    ],

    'title' => 'Definições',
    'subtitle' => 'Preferências sobre a forma como as tuas finanças aparecem na app.',

    'appearance' => [
        'heading' => 'Aspeto',
        'theme' => 'Tema',
        'theme_light' => 'Claro',
        'theme_dark' => 'Escuro',
        'theme_system' => 'Sistema',
        'theme_help' => 'O modo Sistema segue a definição de tema claro ou escuro do teu sistema operativo.',
    ],

    'language' => [
        'apply' => 'Aplicar',
        'heading' => 'Idioma',
        'label' => 'Idioma de apresentação',

        'system' => 'Sistema',
        'help' => 'Muda as palavras no ecrã e a forma como os valores são escritos. O modo Sistema segue o idioma do teu navegador ou sistema operativo, usando o inglês por predefinição.',
    ],

    'country' => [
        'heading' => 'País',
        'label' => 'O teu país',
        'help' => 'Define de que país são as regras fiscais, os organismos públicos e as comissões bancárias que a app reconhece. Não muda o idioma nem a forma como os valores são escritos.',
        'choose' => 'Escolhe um país…',
        'switch_note' => 'Mudar de país acrescenta novas categorias — as etiquetas existentes nunca são alteradas.',

        'wording_note' => 'Os nomes das categorias fiscais aparecem no seu idioma; a declaração de :country usa os seus próprios termos.',

        'countries' => [
            'at' => 'Áustria',
            'be' => 'Bélgica',
            'bg' => 'Bulgária',
            'ca' => 'Canadá',
            'ch' => 'Suíça',
            'cy' => 'Chipre',
            'cz' => 'Chéquia',
            'de' => 'Alemanha',
            'dk' => 'Dinamarca',
            'ee' => 'Estónia',
            'es' => 'Espanha',
            'fi' => 'Finlândia',
            'fr' => 'França',
            'gb' => 'Reino Unido',
            'gr' => 'Grécia',
            'hr' => 'Croácia',
            'hu' => 'Hungria',
            'ie' => 'Irlanda',
            'is' => 'Islândia',
            'it' => 'Itália',
            'lt' => 'Lituânia',
            'lu' => 'Luxemburgo',
            'lv' => 'Letónia',
            'mt' => 'Malta',
            'nl' => 'Países Baixos',
            'no' => 'Noruega',
            'pl' => 'Polónia',
            'pt' => 'Portugal',
            'ro' => 'Roménia',
            'se' => 'Suécia',
            'si' => 'Eslovénia',
            'sk' => 'Eslováquia',
            'us' => 'Estados Unidos',
        ],
    ],

    'currency_display' => [
        'heading' => 'Apresentação do montante',
        'label' => 'Vista predefinida dos montantes',
        'eur_only' => 'Montante liquidado',
        'original' => 'Montante original',
        'help' => 'Aplica-se à lista de transações e aos totais do painel. Podes na mesma alternar página a página, mas só a partir da lista de transações.',
    ],

    'base_currency' => [
        'heading' => 'Moeda base dos relatórios',
        'label' => 'Moeda dos relatórios',
        'help' => 'Todos os totais e resumos são convertidos para esta moeda. Cada conta continua a mostrar a sua própria moeda original ao lado.',
    ],

    'exchange_rates' => [
        'heading' => 'Taxas de câmbio',
        'fetch_online' => 'Obter as taxas atuais online',
        'online_on' => 'Taxas obtidas diariamente do BCE, ou do Frankfurter se o BCE estiver indisponível. Apenas consultas de pares de moedas — sem dados pessoais.',
        'last_updated' => 'Última atualização: :date.',
        'online_off' => 'Continuam a ser usadas as taxas que já existem, com o instantâneo incluído como reserva. Nenhum dado sai deste dispositivo.',
        'fetch_aria' => 'Obter online as taxas de câmbio atuais',
        'refreshing' => 'A atualizar…',
        'next_refresh' => 'Atualização automática: uma vez por dia',
        'refresh_gave_up' => 'Não foi possível atualizar as taxas. Continuam a ser usadas as que já estão neste dispositivo.',
        'refresh_now' => 'Atualizar agora',
    ],

    'period' => [
        'heading' => 'Período',
        'label' => 'O período começa no dia',
        'help' => 'Numerado de 1 a 28. A maioria dos utilizadores mantém 1 (mês de calendário). Usa 25 se o teu salário entra no dia 25 e consideras que o "teu mês" começa aí.',

        'move_confirm' => 'Se o período começar no dia :day, todos os montantes dos envelopes são reorganizados e somados dois a dois onde dois meses se juntam num só. Voltar a mudar o dia não os separa de novo.',
        'move_cancel' => 'Cancelar',
        'move_apply' => 'Aplicar',
    ],

    'recurring' => [
        'heading' => 'Deteção de recorrências',
        'window_label' => 'Janela de deteção (meses)',
        'window_help' => 'Quantos meses de histórico analisar ao agrupar transações em padrões recorrentes.',
        'income_label' => 'Rendimento mínimo (unidades menores)',
        'income_help' => 'Os rendimentos abaixo deste limite não são agrupados automaticamente. Guardado em unidades menores — :minor significa :example. Define 0 para desativar o limite.',
    ],

    'drift' => [
        'heading' => 'Alertas de desvio',
        'label' => 'Limite predefinido dos alertas de desvio',
        'help' => 'Os alertas são disparados quando o montante mais recente de uma cobrança recorrente difere do montante anterior em mais do que esta percentagem. As definições por série têm prioridade.',
        'options' => [
            '1' => '±1%',
            '2' => '±2%',
            '5' => '±5% (predefinição)',
            '10' => '±10%',
            '25' => '±25%',
            '50' => '±50%',
        ],
    ],

    'save' => 'Guardar definições',
    'saved' => 'Guardado.',

    'anomaly_heading' => 'Deteção de anomalias',
    'notifications_heading' => 'Notificações',

    'forecasting' => [
        'heading' => 'Previsão',
        'intro' => 'O Beatrax projeta o teu saldo a partir do estado atual das tuas contas. Para contas sem saldos de extrato (PayPal, importações CSV antigas), define aqui o saldo inicial para que as projeções partam de um ponto conhecido.',
        'no_accounts' => 'Ainda não há contas — importa um extrato para adicionar uma.',
    ],

    'auto_import' => [
        'heading' => 'Importação automática',
        'label' => 'Importação automática a partir da pasta de entrega',

        'active_html' => 'A pasta de entrega está ativa. O Beatrax analisa <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> a cada 5 minutos à procura de ficheiros novos.',
        'inactive_html' => 'Quando está ativa, o Beatrax analisa <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> a cada 5 minutos à procura de ficheiros <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> e <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> e importa-os pelo mesmo pipeline de correspondência do assistente. Os ficheiros processados passam para <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> para nunca serem importados duas vezes.',
        'active_phone_html' => 'A pasta de entrega está ativa. O Beatrax analisa <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> em segundo plano à procura de ficheiros novos. É o teu telemóvel que decide quando corre uma análise em segundo plano, por isso podem passar minutos ou horas.',
        'inactive_phone_html' => 'Quando está ativa, o Beatrax analisa <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> em segundo plano à procura de ficheiros <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> e <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> e importa-os pelo mesmo pipeline de correspondência do assistente. É o teu telemóvel que decide quando corre uma análise em segundo plano, por isso podem passar minutos ou horas. Os ficheiros processados passam para <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> para nunca serem importados duas vezes.',
    ],

    'aliases' => [
        'heading' => 'Aliases',
        'intro' => 'Revê e edita os nomes legíveis que ensinaste ao Beatrax para as descrições crípticas dos extratos.',
        'manage' => 'Gerir aliases →',
    ],

    'tax_heading' => 'Impostos',
    'data_backup_heading' => 'Dados e cópia de segurança',

    'about_updates' => [
        'heading' => 'Sobre as atualizações',
        'body' => 'O Beatrax atualiza-se sozinho depois de instalado. Depois de instalares a primeira versão, as versões seguintes chegam através de um aviso na app — não precisas de voltar ao GitHub. Se alguma atualização futura falhar, podes sempre transferir manualmente o instalador mais recente na página de lançamentos.',
        'body_phone' => 'Aqui o Beatrax não se atualiza sozinho. As novas versões da app para telemóvel chegam pela App Store ou pelo Google Play, tal como as tuas outras apps. A página de lançamentos indica o que mudou em cada uma.',
        'check_label' => 'Procurar atualizações automaticamente',
        'check_on' => 'O Beatrax pergunta ao canal de lançamentos se existe uma versão assinada mais recente. Nada é transferido até escolheres instalá-la.',
        'check_off' => 'Não é feita qualquer procura de atualizações e nada sai deste dispositivo. As novas versões encontram-se abrindo tu mesmo a página de lançamentos.',
        'open_releases' => 'Abrir a página de lançamentos →',
    ],

    'privacy' => [
        'heading' => 'Política de privacidade',
        'body' => 'O Beatrax mantém as tuas finanças nos teus próprios dispositivos. A política explica o que isso significa, o que enviam as funcionalidades online opcionais e como remover os teus dados.',
        'open' => 'Ler a política de privacidade →',
        'url_hint' => 'Se a ligação não abrir, visita:',
    ],

    'first_run_tour' => [
        'heading' => 'Visita da primeira utilização',
        'body' => 'Volta a iniciar o assistente de configuração se quiseres percorrer outra vez o fluxo introdutório.',
        'run_again' => 'Executar o assistente outra vez',
    ],

    'developer' => [
        'heading' => 'Programador',
        'label' => 'Dev Console na app',
        'help' => 'Mostrar a Dev Console em /dev. Repõe a opção Avançado em cada início de sessão.',
        'aria' => 'Modo de programador',
    ],

    'errors' => [
        'period_move_failed' => 'Não foi possível mover o mês de orçamento, por isso ficou onde estava.',
        'currency_required' => 'Escolhe uma moeda.',
        'window_months' => 'Escolhe entre 2 e 60 meses.',
        'threshold' => 'Escolhe um limite de 1%, 2%, 5%, 10%, 25% ou 50%.',
        'amount' => 'Introduz um montante a partir de :zero.',
        'period_day' => 'Escolhe um dia de 1 a 28.',
        'currency_view' => 'Escolhe uma das opções disponíveis.',
    ],
];
