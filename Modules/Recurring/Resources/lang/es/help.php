<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Un extracto es una lista plana de fechas e importes, y nada en él dice qué líneas son el mismo compromiso periódico. Beatrax agrupa las líneas por a quién se pagó, descarta los importes que se salen del grupo y solo propone una serie cuando los huecos entre ellas se asientan en un ritmo estable semanal, mensual, trimestral o anual; cualquier cosa menos regular no se propone nunca. Solo mira hacia atrás hasta donde llega “:setting” en Ajustes, que arranca en el intervalo más corto con el que puede trabajar, así que una factura anual queda fuera de vista hasta que lo amplíes. Aquí no se aplica nada a tus datos hasta que tú lo apruebas.',
];
