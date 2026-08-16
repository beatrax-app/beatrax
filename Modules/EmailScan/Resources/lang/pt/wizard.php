<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Configura o teu cliente OAuth do Gmail',
    'microsoft_title' => 'Configura o teu cliente OAuth do Microsoft 365',
    'intro' => 'O Beatrax usa o teu próprio projeto do Google Cloud / registo de aplicação do Azure, para que as tuas credenciais nunca passem por um servidor partilhado. Esta configuração é feita uma só vez por fornecedor.',

    'copied' => 'Copiado',
    'cancel' => 'Cancelar',
    'save_connect' => 'Guardar e ligar',

    'secret_help' => 'Ficam guardadas num ficheiro de configuração local, fora da base de dados, com permissões restritivas, e nunca saem deste dispositivo.',

    'gmail' => [
        'step1_title' => 'Abre a Google Cloud Console',
        'step1_body' => 'Abre a Google Cloud Console num separador novo. Inicia sessão com a conta Google que queres analisar e depois cria um projeto novo (ou seleciona um projeto pessoal existente).',
        'step1_link' => 'Abrir a Google Cloud Console',
        'step2_title' => 'Ativa a Gmail API',
        'step2_body' => 'No projeto novo, procura "Gmail API" na API Library e clica em Enable. Isto dá ao projeto a capacidade de contactar o Gmail em teu nome.',
        'step3_title' => 'Configura o ecrã de consentimento OAuth',
        'step3_body' => 'Abre APIs & Services → OAuth consent screen. Escolhe o User type "External", introduz "Beatrax" como nome da aplicação e o teu próprio e-mail como contacto de apoio e contacto de programador. Adiciona o âmbito https://www.googleapis.com/auth/gmail.readonly. Clica em Save and continue e depois em Back to Dashboard.',
        'step4_title' => 'Passa o ecrã de consentimento para "In production"',
        'step4_body' => 'Na página do OAuth consent screen, clica em Publish App e confirma. Isto é obrigatório — sem isso, os refresh tokens que o Beatrax recebe expiram ao fim de 7 dias. Publicar não exige qualquer revisão da Google quando o único utilizador és tu.',
        'step4_checkbox' => 'Publiquei o ecrã de consentimento OAuth em In production',
        'step5_title' => 'Cria o OAuth Client ID',
        'step5_body' => 'Abre Credentials → Create Credentials → OAuth Client ID. Escolhe o application type "Web application". Define o nome "Beatrax". Em "Authorized redirect URIs" cola exatamente o URI abaixo.',
        'step6_title' => 'Cola o teu client ID e client secret',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Abre o Azure Portal',
        'step1_body' => 'Abre o Microsoft Entra admin center num separador novo. Inicia sessão com a conta Microsoft que queres analisar.',
        'step1_link' => 'Abrir o Azure Portal',
        'step2_title' => 'Regista uma aplicação nova',
        'step2_body' => 'Abre App registrations → New registration. Dá-lhe o nome "Beatrax". Em "Supported account types" escolhe "Accounts in any organizational directory and personal Microsoft accounts" (isto permite-te ligar caixas de correio pessoais do Outlook.com e profissionais do Microsoft 365 com a mesma aplicação).',
        'step3_title' => 'Adiciona o redirect URI',
        'step3_body' => 'No mesmo formulário de registo, em "Redirect URI", escolhe a plataforma "Web" e cola exatamente o URI abaixo.',
        'step4_title' => 'Concede a permissão Mail.Read',
        'step4_body' => 'Abre API permissions → Add a permission → Microsoft Graph → Delegated permissions. Seleciona Mail.Read e offline_access. Clica em Add permissions. Não precisas de conceder consentimento de administrador para uma conta pessoal.',
        'step5_title' => 'Cria um client secret',
        'step5_body' => 'Abre Certificates & secrets → New client secret. Define a descrição "Beatrax" e uma validade de 24 meses. Copia o valor do secret de imediato — o Azure só o mostra uma vez.',
        'step6_title' => 'Cola o teu application (client) ID e o secret',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Valor do client secret',
    ],

    'errors' => [
        'pick_provider' => 'Escolhe um fornecedor antes de submeteres.',
        'microsoft_client_id' => 'Introduz o application (client) ID — um UUID como 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Introduz o valor do client secret que o Azure te mostrou quando criaste o secret.',
        'google_client_id' => 'Introduz um client ID OAuth da Google terminado em .apps.googleusercontent.com.',
        'google_secret' => 'Introduz um client secret OAuth da Google começado por GOCSPX-.',
        'google_published' => "Confirma que passaste o ecrã de consentimento OAuth para 'In production'.",
        'write_failed' => 'Não foi possível guardar o teu cliente OAuth no disco — verifica as permissões da tua pasta de segredos e tenta novamente.',
    ],
];
