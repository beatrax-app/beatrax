<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count cambio se hizo con una versión más reciente de Beatrax|:count cambios se hicieron con una versión más reciente de Beatrax',
        'body' => 'Lo que se rechazó nombra algo que esta versión de Beatrax no tiene, así que este dispositivo no tenía dónde ponerlo. Sigue en el dispositivo que lo hizo, y no se ha eliminado nada tuyo.',
        'action' => 'Actualiza Beatrax en este dispositivo. Los cambios hechos después de la actualización llegan con normalidad, pero nada que ya se haya rechazado se vuelve a enviar — vuelve a hacer el cambio aquí si también lo necesitas en este dispositivo.',
    ],
    'untrusted_author' => [
        'summary' => ':count cambio fue firmado por un dispositivo que este no reconoce|:count cambios fueron firmados por un dispositivo que este no reconoce',
        'body' => 'Lo que se rechazó vino de un dispositivo que nunca se vinculó con este, o de uno que eliminaste. Aquí no se escribió nada, y nada de lo que ya había aquí cambió.',
        'action' => 'Si eliminaste ese dispositivo tú mismo, esto es justo lo que hace eliminarlo y no hay nada que arreglar. Si no fuiste tú, revisa la lista de dispositivos de esta página.',
    ],
    'not_verified' => [
        'summary' => ':count cambio no pasó la comprobación de seguridad en este dispositivo|:count cambios no pasaron la comprobación de seguridad en este dispositivo',
        'body' => 'Una firma no coincidía con el dispositivo que decía haber hecho el cambio, o el cambio iba dirigido a otra cuenta. Aquí no se escribió nada. Entre tus propios dispositivos esto no debería ocurrir.',
        'action' => 'Revisa la lista de dispositivos de esta página y elimina todo lo que no reconozcas. Si todos los dispositivos de ahí son tuyos y esto se repite, es un fallo de Beatrax y no algo que puedas arreglar desde aquí.',
    ],
    'diverged' => [
        'summary' => ':count cambio de otro dispositivo no se pudo guardar aquí|:count cambios de otro dispositivo no se pudieron guardar aquí',
        'body' => 'Llegó algo que este dispositivo no pudo almacenar: un registro al que le falta una parte de sí mismo, una fecha que no existe, un desglose que ya no cuadra, un registro al que dos dispositivos ya habían dado la misma identidad, o una eliminación de algo que aquí todavía está en uso. Lo que se rechazó está en tu otro dispositivo y no en este, así que los dos ya no contienen lo mismo.',
        'action' => 'Compara el registro de tu otro dispositivo con lo que ves aquí y vuelve a hacer el cambio aquí — o vuelve a eliminarlo aquí, si algo que borraste en otro sitio sigue estando aquí. Lo rechazado no se vuelve a enviar por sí solo.',
    ],
    'last_seen' => 'Más reciente: :when',
];
