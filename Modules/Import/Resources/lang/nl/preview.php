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
    'unreadable_html' => 'De voorvertoning kan niet worden gelezen. <a href="/imports/new" class="underline">Upload het bestand opnieuw</a> om het nog eens te proberen.',

    'save_name' => 'Naam opslaan',
    'account_name_label' => 'Rekeningnaam',
    'account_placeholder' => 'bijv. Spaarrekening',
    'rename_aria' => 'Deze tegenpartij hernoemen',

    'unknown_iban_prefix' => 'We vonden een onbekend IBAN:',

    'unknown_account_prefix' => 'We vonden een onbekende rekening:',
    'unknown_iban_suffix' => 'Geef deze rekening een naam.',

    'ics' => [
        'name' => 'ICS-kaart',
        'heading' => 'Geef je ICS-kaartrekening een naam.',
        'help' => 'Dit is de eerste keer dat je ICS-gegevens importeert. Geef deze kaart een naam zodat hij overal in de app consistent verschijnt.',
        'placeholder' => 'bijv. ICS-kaart',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Geef je PayPal-rekening een naam.',
        'help' => 'Dit is de eerste keer dat je PayPal-gegevens importeert. Geef deze wallet een naam zodat hij overal in de app consistent verschijnt.',
        'placeholder' => 'bijv. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Geef je Google Play-rekening een naam.',
        'help' => 'Dit is de eerste keer dat je een Google Play-bon importeert. Geef deze rekening een naam zodat hij overal in de app consistent verschijnt.',
        'placeholder' => 'bijv. Google Play',
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

    'rows_shown' => 'Regels getoond: :shown van :total',

    'show_more' => 'Meer regels tonen',

    'errors' => [
        'app_locked' => 'Ontgrendel de app om te importeren: de versleutelingssleutels kunnen niet worden gebruikt zolang hij vergrendeld is.',
        'archive_holds_one_message' => 'Dit bestand is één e-mailbericht, geen mailbox-archief, dus als archief gelezen zit er niets in. Upload het opnieuw met het formaat op E-mailbericht.',
        'email_file_is_an_archive' => 'Dit bestand is een mailbox-archief: het bevat meer dan één bericht, en als één bericht gelezen zou alleen het eerste worden overgenomen. Upload het opnieuw met het formaat op Mailbox-archief.',
        'file_stopped_short' => 'De kopregel klopte, dus het formaat is goed. Het lezen stopte voor het einde van het bestand. Eén onleesbare regel doet dat, en een bestand dat te groot is voor dit apparaat ook. Probeer een kortere periode.',
        'file_unreadable' => 'Dit bestand kon niet worden gelezen.',
        'file_unreadable_detail' => 'De app kon dit bestand niet lezen (:code). De volledige gegevens staan in het app-logboek; vermeld deze code als je een probleem meldt.',
        'iban_not_in_preview' => 'Dit IBAN maakt geen deel uit van de huidige voorvertoning.',
        'not_an_email_file' => 'Dit bestand is geen e-mailbericht en geen mailbox-archief, dus er valt niets uit te lezen als bon. Kies het importtype en het formaat die bij je bestand passen.',
        'pdf_has_no_text_layer' => 'Deze pdf bevat geen tekst — het is een scan of een foto van een afschrift, dus er valt niets uit te lezen. Download het afschrift zelf bij je bank, of gebruik een CSV-export.',
        'pdf_password_protected' => 'Deze pdf is beveiligd met een wachtwoord, dus geen enkele lezer krijgt hem open. Sla vanuit je pdf-viewer een onbeveiligde kopie op en importeer die.',
        'pdf_reader_unavailable' => 'Deze versie van de app heeft helemaal geen pdf-lezer, dus een pdf-afschrift kan hier niet worden geopend. Importeer dit bestand op een ander apparaat, of gebruik een CSV-export van je bank.',
        'row_belongs_to_another_statement' => 'Deze regel hoort bij een transactie in een ander afschriftbestand. Importeer dat afschrift ook — de twee worden samen gelezen.',
        'row_unreadable' => 'Deze regel kon niet worden gelezen.',
        'row_unreadable_detail' => 'De app kon deze regel niet lezen (:code). De volledige gegevens staan in het app-logboek; vermeld deze code als je een probleem meldt.',
        'unknown_account' => 'Deze regel hoort bij een rekening die je nog geen naam hebt gegeven.',
    ],

    'receipts' => [
        'heading' => 'Dit bestand is als e-mail gelezen',
        'saved' => 'Wat erin zat staat hieronder, en elk bericht is bewaard.',
        'none_imported' => 'Niets daarvan is een transactie geworden, dus er is niets aan je grootboek toegevoegd.',
        'shown' => 'Berichten getoond: :shown van :total',
        'no_subject' => 'Geen onderwerp',

        'state' => [
            'read' => 'Als betaling gelezen — bevestig deze import om hem aan je grootboek toe te voegen.',
            'not_a_payment' => 'Geen betaling. Dit bericht kondigt iets aan in plaats van een betaling te bevestigen.',
            'unreadable' => 'Bewaard. De app leest bonnen van deze afzender, maar vond in dit bericht geen bedrag, winkelier en kenmerk.',
            'unknown_sender' => 'Bewaard. De app leest geen bonnen van deze afzender, dus er is niets uit het bericht overgenomen.',
        ],
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
