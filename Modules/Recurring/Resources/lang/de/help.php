<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Ein Kontoauszug ist eine flache Liste aus Daten und Beträgen; nichts darin sagt, welche Zeilen dieselbe laufende Verpflichtung sind. Beatrax gruppiert Zeilen nach Empfänger, verwirft Beträge, die aus der Gruppe herausfallen, und schlägt eine Serie erst vor, wenn sich die Abstände zu einem festen wöchentlichen, monatlichen, vierteljährlichen oder jährlichen Rhythmus fügen — alles Unregelmäßigere wird gar nicht erst vorgeschlagen. Zurück gelesen wird nur so weit wie „:setting“ in den Einstellungen, und das beginnt beim kürzesten Zeitraum, mit dem sich arbeiten lässt: eine jährliche Rechnung bleibt also unsichtbar, bis du ihn erweiterst. An deinen Daten ändert sich hier nichts, bevor du zustimmst.',
];
