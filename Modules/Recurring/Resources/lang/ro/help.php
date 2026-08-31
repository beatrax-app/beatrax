<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Un extras este o listă plată de date și sume, iar nimic din el nu spune care rânduri sunt același angajament permanent. Beatrax grupează rândurile după cine a fost plătit, elimină sumele care ies din tiparul grupului și propune o serie abia când intervalele dintre ele se așază într-un ritm constant săptămânal, lunar, trimestrial sau anual — orice este mai neregulat nu este propus deloc. Citește în urmă doar cât ajunge „:setting” din setări, iar acesta pornește de la cel mai scurt interval cu care poate lucra, așa că o factură anuală rămâne nevăzută până nu îl lărgești. Nimic de aici nu se aplică datelor tale înainte să aprobi.',
];
