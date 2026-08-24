<?php

declare(strict_types=1);

return [
    'page_title' => 'Import voorvertonen',
    'heading' => 'Import voorvertonen',
    'discard' => 'Import weggooien',
    'confirm' => 'Import bevestigen',
    'subtitle' => 'Bekijk de ingelezen regels. Er wordt niets in je grootboek opgeslagen totdat je bevestigt.',

    'already_imported' => 'Dit bestand is al geïmporteerd.',

    'already_imported_link' => 'Bekijk het importresultaat',

    'expired_html' => 'De voorvertoning is verlopen. <a href="/imports/new" class="underline">Upload het bestand opnieuw</a> om het nog eens te proberen.',

    'save_name' => 'Naam opslaan',
    'account_name_label' => 'Rekeningnaam',
    'account_placeholder' => 'bijv. Spaarrekening',
    'rename_aria' => 'Deze tegenpartij hernoemen',

    'unknown_iban_prefix' => 'We vonden een onbekend IBAN:',
    'unknown_iban_suffix' => 'Geef deze rekening een naam.',

    'ics' => [
        'heading' => 'Geef je ICS-kaartrekening een naam.',
        'help' => 'Dit is de eerste keer dat je ICS-gegevens importeert. Geef deze kaart een naam zodat hij overal in de app consistent verschijnt.',
        'placeholder' => 'bijv. ICS-kaart',
    ],

    'paypal' => [
        'heading' => 'Geef je PayPal-rekening een naam.',
        'help' => 'Dit is de eerste keer dat je PayPal-gegevens importeert. Geef deze wallet een naam zodat hij overal in de app consistent verschijnt.',
        'placeholder' => 'bijv. PayPal',
    ],

    'col_date' => 'Datum',
    'col_funding_source' => 'Financieringsbron',
    'col_counterparty' => 'Tegenpartij',
    'col_amount' => 'Bedrag',
    'col_status' => 'Status',

    'status' => [
        'new' => 'Nieuw',
        'new_title' => 'Wordt aan je grootboek toegevoegd.',
        'duplicate' => 'Duplicaat',
        'duplicate_title' => 'Al geïmporteerd — wordt overgeslagen.',
        'enriched' => 'Verrijkt',
        'enriched_title' => 'Bestaande regel wordt bijgewerkt met een sterkere bronverwijzing.',
        'error' => 'Fout',
    ],

    'chain' => [
        'heading' => 'Ketens oplossen…',
        'pending' => 'In wachtrij. De keten-oplosser start zo dadelijk.',
        'running' => 'Financieringsketens koppelen en afschriftverrekeningen ontleden.',
        'failed_prefix' => 'Keten oplossen mislukt:',
        'failed_detail' => 'de details staan in het joblogboek',
        'open_horizon' => 'Horizon openen',
        'failed_suffix' => 'om opnieuw te proberen of te inspecteren.',
    ],

    'errors' => [
        'app_locked' => 'Ontgrendel de app om te importeren: de versleutelingssleutels kunnen niet worden gebruikt zolang hij vergrendeld is.',
        'file_unreadable' => 'Dit bestand kon niet worden gelezen.',
        'iban_not_in_preview' => 'Dit IBAN maakt geen deel uit van de huidige voorvertoning.',
        'pdf_reader_unavailable' => 'Voor pdf-afschriften is het programma pdftotext nodig, en dat is hier niet geïnstalleerd. Importeer dit bestand op een desktop waar het wel staat, of gebruik een CSV-export van je bank.',
        'row_unreadable' => 'Deze regel kon niet worden gelezen.',
        'unknown_account' => 'Deze regel hoort bij een rekening die je nog geen naam hebt gegeven.',
    ],

    'failed' => [
        'heading' => 'Dit bestand kon niet worden gelezen',
        'no_rows' => 'Er zijn geen transacties in dit bestand gevonden, dus er is niets om te importeren.',
        'nothing_read' => 'Niets in dit bestand kon als transactie worden gelezen, dus er is niets om te importeren.',
        'every_row' => 'Geen enkele regel in dit bestand kon worden gelezen, dus er is niets om te importeren. Elke regel staat hieronder met de reden.',
        'likely_cause' => 'Meestal komt dat doordat de kopregel niet past bij de bron die je hebt gekozen. Controleer de bank en het formaat op het uploadscherm, of download het afschrift opnieuw bij je bank.',
        'truncated_heading' => 'Slechts een deel van dit bestand kon worden gelezen',
        'truncated' => 'Het inlezen is halverwege het bestand gestopt. Alles na dat punt is niet gelezen en wordt niet geïmporteerd.',
        'some_rows' => 'Sommige regels konden niet worden gelezen. Ze zijn hieronder gemarkeerd en worden overgeslagen; bevestigen importeert de rest.',
        'detail_label' => 'Wat de parser meldde:',
        'rows_read_label' => 'Regels gelezen',
        'rows_skipped_label' => 'Regels overgeslagen',
    ],
];
