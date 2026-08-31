<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Ett kontoutdrag är en platt lista med datum och belopp, och inget i den säger vilka rader som är samma löpande åtagande. Beatrax grupperar raderna efter vem som fick betalt, förkastar belopp som sticker ut från gruppen och föreslår en serie först när mellanrummen lägger sig i en stadig vecko-, månads-, kvartals- eller årsrytm — allt som är mindre regelbundet föreslås aldrig. Den läser bara så långt bakåt som ”:setting” under Inställningar, och det börjar på den kortaste period den kan arbeta med, så en årlig räkning syns inte förrän du vidgar den. Inget här ändrar dina data förrän du godkänner det.',
];
