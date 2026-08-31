<?php

declare(strict_types=1);

return [
    'page_title' => 'Regels',
    'heading' => 'Regels',
    'intro' => 'Categoriseer transacties al bij het importeren. Regels gelden voor elke bron — bank, kaart, PayPal en e-mailbonnen.',
    'device_local_note' => 'Regels blijven op dit apparaat. Ze worden niet gedeeld met je andere apparaten.',

    'reapply' => 'Regels opnieuw op geschiedenis toepassen',
    // i18n-review: nl · reapply_confirm — "afgeletterd" follows this file's own
    // summary_reconciled_skipped, while ledger::reconcile calls the same state
    // "afgestemd". One of the two is the word Dutch readers actually use.
    'reapply_confirm' => 'Alle regels opnieuw op je hele geschiedenis toepassen? Elke categorie, winkelier, notitie en elk belastinglabel dat een regel heeft gezet, wordt overschreven. Wat je met de hand hebt ingevuld blijft staan, net als alles op een afgeletterd afschrift. Niets zet de oude waarden terug.',
    'reapplying' => 'Opnieuw toepassen…',
    'new_rule' => 'Nieuwe regel',

    'reapply_progress' => 'Regels opnieuw toepassen… :checked van :count transactie gecontroleerd|Regels opnieuw toepassen… :checked van :count transacties gecontroleerd',

    'empty_heading' => 'Nog geen regels',
    'empty_body' => 'Regels vergelijken transacties op meerdere voorwaarden en passen automatisch categorie-, winkelier-, notitie- en belastinglabelwijzigingen toe — bij het importeren, en telkens wanneer je ze opnieuw op je bestaande geschiedenis toepast.',
    'empty_cta' => 'Maak je eerste regel',

    'col_priority' => 'Prioriteit',
    'col_conditions' => 'Voorwaarden',
    'col_actions' => 'Acties',
    'col_hits' => 'Treffers',
    'col_created' => 'Aangemaakt',
    'col_row_actions' => 'Acties',
    'inactive_badge' => 'Uit',
    'combinator_all' => 'ALLE',
    'combinator_any' => 'MINSTENS ÉÉN',
    'inactive_title' => 'Deze regel draait niet. Een regel gaat uit wanneer de categorie of tegenpartij waarnaar hij verwijst wordt verwijderd.',

    'more_conditions' => '+:count meer',

    'delete_confirm' => 'Verwijderen?',
    'delete_yes' => 'Ja, verwijderen',
    'cancel' => 'Annuleren',
    'edit' => 'Bewerken',
    'delete' => 'Verwijderen',
    'edit_aria' => 'Regel bewerken (prioriteit :priority)',
    'delete_aria' => 'Regel verwijderen (prioriteit :priority)',

    'footer_note' => 'Regels en winkeliergeschiedenis werken samen. Het verwijderen van een regel wist niet wat Beatrax uit eerdere categorisaties heeft geleerd — bij de volgende import kan dezelfde categorie nog steeds automatisch uit de geschiedenis worden voorgesteld.',

    'chip_category' => 'Categorie: :path',
    'chip_counterparty' => 'Winkelier: :path',
    'chip_note' => 'Notitie',
    'chip_tax_tag' => 'Belastinglabel',

    'flash_deleted' => 'Regel verwijderd.',
    'flash_not_found' => 'Regel niet gevonden (mogelijk verwijderd in een ander tabblad).',
    'flash_saved' => 'Regel opgeslagen.',
    'flash_reapplying' => 'Regels opnieuw op je geschiedenis toepassen…',
    'summary_no_changes' => 'Geen wijzigingen — je geschiedenis komt al overeen met je regels.',
    'summary_updated' => ':fields bijgewerkt in :transactions.',
    'summary_fields' => ':count veld|:count velden',
    'summary_transactions' => ':count transactie|:count transacties',
    'summary_reconciled_skipped' => ':count afgeletterde transactie is overgeslagen.|:count afgeletterde transacties zijn overgeslagen.',
];
