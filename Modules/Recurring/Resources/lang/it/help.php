<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Un estratto conto è un elenco piatto di date e importi, e niente al suo interno dice quali righe sono lo stesso impegno ricorrente. Beatrax raggruppa le righe per beneficiario, scarta gli importi fuori linea rispetto al gruppo e propone una serie solo quando gli intervalli tra le righe si assestano su un ritmo stabile settimanale, mensile, trimestrale o annuale: tutto ciò che è meno regolare non viene mai proposto. Guarda indietro solo fin dove arriva “:setting” nelle impostazioni, che parte dall’intervallo più breve con cui può lavorare, quindi una bolletta annuale resta invisibile finché non lo allarghi. Qui non viene applicato nulla ai tuoi dati finché non approvi.',
];
