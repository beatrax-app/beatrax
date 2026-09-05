<?php

declare(strict_types=1);

return [
    'page_title' => 'Este dispositivo está sincronizado',
    'heading' => 'Este dispositivo está sincronizado',
    'records' => 'Se ha copiado :count registro desde :peer.|Se han copiado :count registros desde :peer.',
    'records_none' => 'Ya está al día con :peer. No había nada nuevo que copiar.',
    'withheld' => ':count cambio aún no ha llegado.|:count cambios aún no han llegado.',
    'withheld_action' => 'Firmados por un dispositivo que este no puede comprobar. No se pierde nada — todo se queda en :peer y llegará si alguno de tus dispositivos pasa esa identidad y tú la confirmas en :section.',
    'how_it_works' => 'A partir de ahora',
    'automatic_title' => 'Tú eliges cuándo se sincroniza',
    'automatic_body' => 'Todo lo que cambies en cualquiera de los dos dispositivos aparece en el otro la próxima vez que toques :action. No puede ejecutarse en segundo plano — el bloqueo de la app guarda la única clave.',
    'lan_title' => 'En la misma red',
    'lan_body' => 'Cuando los dos dispositivos están en tu red doméstica, se comunican directamente entre sí, sin nada de por medio.',
    'relay_title' => 'Cuando estás fuera',
    'relay_body' => 'Los cambios esperan cifrados en tu relay hasta que el otro dispositivo vuelve a estar en línea. Este dispositivo los recoge la próxima vez que toques :action.',
    'no_relay_title' => 'Cuando estás fuera',
    'no_relay_body' => 'Los cambios esperan en este dispositivo hasta que ambos coincidan en tu red doméstica y toques :action aquí.',
    'encrypted_title' => 'Solo tus dispositivos pueden leerlo',
    'encrypted_body' => 'Todo se cifra antes de salir de un dispositivo, y solo tus dispositivos vinculados tienen las claves.',
    'continue' => 'Empezar a usar Beatrax',
    'peer_fallback' => 'tu otro dispositivo',
];
