<?php

declare(strict_types=1);

return [
    'about_body' => 'Um ficheiro YAML incluído que faz corresponder códigos crípticos de extratos bancários a nomes de comerciantes legíveis. Ao ativar, deixas o Beatrax ler a lista durante a importação; ao enviar uma sugestão, o GitHub abre no teu navegador.',

    'mappings' => ':count correspondência|:count correspondências',
    'contributors' => ':count contribuidor|:count contribuidores',

    'use_shared_list' => [
        'title' => 'Usar a lista de comerciantes partilhada',
        'help' => 'Deixa o Beatrax ler a lista incluída para preencher nomes legíveis dos comerciantes que ainda não renomeaste.',
    ],

    'offer_to_contribute' => [
        'title' => 'Oferecer para contribuir',
        'help' => 'Mostra o botão "Ajuda outros a identificar isto" na linha de triagem, para poderes enviar uma sugestão para a lista partilhada com um clique.',
    ],

    'update_on_updates' => [
        'title' => 'Atualizar a lista partilhada nas atualizações da app',
        'help' => 'Atualiza a lista incluída sempre que o Beatrax se atualiza.',
        'help_phone' => 'Atualiza a lista incluída sempre que uma nova versão do Beatrax é instalada a partir da App Store ou do Google Play.',
        'note' => 'Fica ativo com uma futura atualização da app — vê Definições → Sobre para a versão atual.',
    ],
];
