<?php

declare(strict_types=1);

return [
    'page_title' => 'Importera från YNAB / Actual',

    'eyebrow' => 'Migreringar',
    'heading' => 'Importera från YNAB / Actual',
    'intro' => 'Ta med dig ditt kategoriträd, din budgethistorik och dina transaktioner från YNAB4, nya YNAB eller Actual Budget. Ingenting skrivs till dina transaktioner förrän du har granskat och bekräftat.',
    'reconcile_context' => 'Söker efter uppdateringar mot din senaste import från :product.',

    'source_label' => 'Källa',
    'file_label' => 'Fil',
    'parse_button' => 'Läs in exporten',

    'hints' => [
        'ynab4' => 'Exportera hela din budget som en ZIP-fil från menyn File → Export i YNAB4.',
        'nynab' => 'Exportera din budget från nYNAB via File → Export Budget och packa sedan de exporterade CSV-filerna i en ZIP-fil.',
        'actual' => 'Exportera din budget som en ZIP-fil från Settings → Export data i Actual Budget.',
    ],

    'errors' => [
        'unrecognised' => 'Det här ser inte ut som en export från YNAB4, nYNAB eller Actual som vi kan läsa. Kontrollera filen och försök igen.',
        'file_too_large' => 'Filen är för stor för en migreringsexport.',
        'archive_reader_unavailable' => 'Den här versionen av appen har ingen ZIP-läsare som kan öppna den här exporten, så den går inte att läsa här. Importera den i skrivbordsappen, eller packa om exporten med vanlig komprimering.',
        'internal_detail' => 'Appen kunde inte läsa den här exporten (:code). De fullständiga uppgifterna finns i apploggen; ange den här koden om du rapporterar ett problem.',
    ],
];
