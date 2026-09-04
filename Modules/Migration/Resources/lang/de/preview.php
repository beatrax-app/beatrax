<?php

declare(strict_types=1);

return [
    'page_title' => 'Import-Vorschau',

    'heading' => 'Import-Vorschau',
    'subtitle' => 'Sieh dir an, was sich ändert. Bis du bestätigst, wird nichts gespeichert.',

    'stats' => [
        'category' => 'Kategorien',
        'account' => 'Konten',
        'payee' => 'Zahlungspartner',
        'transaction' => 'Transaktionen',
        'budget' => 'Budgetmonate',
    ],

    'all_clean' => 'Alles sauber zugeordnet — hier gibt es nichts zu entscheiden.',

    'nothing_staged' => 'Dieser Export enthielt nichts zum Importieren — hier gibt es nichts zu bestätigen.',

    'discarded' => 'Du hast diesen Import verworfen, hier gibt es also nichts mehr in der Vorschau.',
    'discarded_link' => 'Neuen Import starten',

    'groups' => [
        'conflict' => 'Braucht deine Entscheidung',
        'extra' => 'Nicht importiert',
    ],

    'keep_or_take_aria' => 'Lokal behalten oder Quelle übernehmen für :label',
    'keep_local' => 'Lokal behalten',
    'take_source' => 'Quelle übernehmen',

    'footer_note' => 'Damit werden die oben gezeigten Anzahlen in deinen Kategorien, Budgets und in deinem Hauptbuch angelegt oder aktualisiert.',
    'discard_button' => 'Import verwerfen',
    'discard_confirm' => 'Diesen Import verwerfen? Alles, was aus deiner Exportdatei gelesen wurde, wird hier gelöscht, und zurückholen heißt, die ganze Datei erneut hochzuladen und einzulesen. In dein Hauptbuch ist noch nichts gelangt.',
    'confirm_button' => 'Import bestätigen',
];
