<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/import/architecture.md#applying-enrichments */
    'preview_status' => 'Lo que hará la confirmación con cada fila. “:new” se añade a tu contabilidad; “:duplicate” ya está ahí de una importación anterior y se salta, así que volver a importar un extracto que se solapa con otro no cuesta nada; “:enriched” coincide con una fila que ya tienes y completa un detalle que el primer archivo no traía, sin añadir una segunda. Nada en esta pantalla ha tocado todavía tu contabilidad: “:confirm” es el momento en que lo hace.',
];
