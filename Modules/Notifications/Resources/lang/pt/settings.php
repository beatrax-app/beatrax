<?php

declare(strict_types=1);

return [
    'what_heading' => 'Sobre o que te notificar',
    'background_note' => 'O Beatrax prepara-as enquanto a app está aberta. Uma execução agendada em segundo plano não consegue — o bloqueio da app guarda a única chave — por isso o que estiver pendente é recolhido enquanto continuas a usar a app.',
    'background_note_phone' => 'O Beatrax prepara-as enquanto a app está aberta. Em segundo plano não pode — o bloqueio da app guarda a única chave — por isso o que estiver pendente chega da próxima vez que abrires a app.',

    'reminders' => [
        'label' => 'Lembretes de pagamento',
        'help' => 'Recebe um aviso antes de um pagamento recorrente vencer.',
    ],

    'lead_days' => [
        'label' => 'Lembrar-me ___ dias antes',
        'help' => 'Quantos dias antes da data de vencimento é enviado o lembrete. 1–30 dias.',
    ],

    'budget_nudges' => [
        'label' => 'Avisos de orçamento',
        'help' => 'Sê avisado quando o orçamento de uma categoria estiver quase esgotado.',
    ],

    'digest' => [
        'label' => 'A tua posição semanal',
        'help' => 'Com que frequência recebes um resumo da situação neste período.',
        'daily' => 'Diário',
        'weekly' => 'Semanal',
        'off' => 'Desligado',
    ],

    'savings' => [
        'label' => 'Sugestões de poupança',
        'help' => 'Sê avisado quando o Beatrax encontrar um plano mais barato ou uma forma de poupares.',
    ],

    'when_heading' => 'Quando e como',

    'quiet_hours' => [
        'label' => 'Horas de silêncio',
        'help' => 'Sem som nem notificação no ecrã durante este intervalo — as notificações continuam a chegar à tua caixa de entrada.',
        'from' => 'De',
        'to' => 'Até',
    ],

    'hide_details' => [
        'label' => 'Ocultar detalhes nas notificações',
        'help' => 'Oculta montantes e nomes de comerciantes na própria notificação. Liga se o teu ecrã puder ser visto por outras pessoas.',
    ],

    'save' => 'Guardar as definições de notificações',
    'saved' => 'Guardado.',

    'other_devices' => [
        'summary' => 'Outros dispositivos',
        'empty' => 'Ainda não há outros dispositivos emparelhados.',
        'unnamed' => 'Dispositivo sem nome',

        'summary_line' => 'lembretes :reminders · avisos :nudges · resumo :digest · poupança :savings',
        'on' => 'ligado',
        'off' => 'desligado',
    ],

    'errors' => [
        'save_failed' => 'Não foi possível guardar as tuas definições de notificações. Tenta de novo.',
    ],
];
