<?php

declare(strict_types=1);

return [
    'what_heading' => 'De qué quiero recibir avisos',
    'background_note' => 'Beatrax las prepara mientras la app está abierta. Una ejecución programada en segundo plano no puede — el bloqueo de la app guarda la única clave —, así que lo pendiente se recupera mientras sigues usando la app.',
    'background_note_phone' => 'Beatrax las prepara mientras la app está abierta. En segundo plano no puede — el bloqueo de la app guarda la única clave —, así que lo pendiente llega la próxima vez que abras la app.',

    'reminders' => [
        'label' => 'Recordatorios de pago',
        'help' => 'Recibe un aviso antes de que venza un pago recurrente.',
    ],

    'lead_days' => [
        'label' => 'Avisarme ___ días antes',
        'help' => 'Cuántos días antes de la fecha de vencimiento salta el recordatorio. De 1 a 30 días.',
    ],

    'budget_nudges' => [
        'label' => 'Avisos de presupuesto',
        'help' => 'Recibe un aviso cuando el presupuesto de una categoría esté casi agotado.',
    ],

    'digest' => [
        'label' => 'Tu situación semanal',
        'help' => 'Con qué frecuencia recibes un resumen de cómo va el periodo.',
        'daily' => 'A diario',
        'weekly' => 'Cada semana',
        'off' => 'Desactivado',
    ],

    'savings' => [
        'label' => 'Avisos de oportunidades de ahorro',
        'help' => 'Recibe un aviso cuando Beatrax detecte un plan más barato o algo en lo que puedas ahorrar.',
    ],

    'when_heading' => 'Cuándo y cómo',

    'quiet_hours' => [
        'label' => 'Horas de silencio',
        'help' => 'Sin sonido ni banner durante este intervalo — las notificaciones siguen llegando a tu bandeja.',
        'from' => 'Desde',
        'to' => 'Hasta',
    ],

    'hide_details' => [
        'label' => 'Ocultar detalles en las notificaciones',
        'help' => 'Oculta los importes y los nombres de los comercios en el propio banner de la notificación. Actívalo si otras personas pueden ver tu pantalla.',
    ],

    'save' => 'Guardar los ajustes de notificaciones',
    'saved' => 'Guardado.',

    'other_devices' => [
        'summary' => 'Otros dispositivos',
        'empty' => 'Todavía no hay otros dispositivos vinculados.',
        'unnamed' => 'Dispositivo sin nombre',

        'summary_line' => 'recordatorios :reminders · avisos :nudges · resumen :digest · ahorro :savings',
        'on' => 'activado',
        'off' => 'desactivado',
    ],

    'errors' => [
        'save_failed' => 'No se han podido guardar tus ajustes de notificaciones. Inténtalo de nuevo.',
    ],
];
