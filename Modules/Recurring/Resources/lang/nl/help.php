<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Een afschrift is een platte lijst met datums en bedragen; niets erin zegt welke regels dezelfde vaste verplichting zijn. Beatrax groepeert regels op wie er betaald is, laat de bedragen vallen die uit de toon van de groep vallen, en stelt pas een reeks voor als de tussenpozen zich zetten in een vast wekelijks, maandelijks, per kwartaal of jaarlijks ritme — alles wat onregelmatiger is, wordt nooit voorgesteld. Het kijkt niet verder terug dan ‘:setting’ in Instellingen, en dat begint op de kortste periode waarmee het kan werken, dus een jaarlijkse rekening blijft buiten beeld tot je die verruimt. Er verandert hier niets aan je gegevens tot jij het goedkeurt.',
];
