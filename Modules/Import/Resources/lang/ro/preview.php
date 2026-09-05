<?php

declare(strict_types=1);

return [
    'page_title' => 'Previzualizează importul',
    'heading' => 'Previzualizează importul',
    'discard' => 'Renunță la import',
    'confirm' => 'Confirmă importul',
    'subtitle' => 'Verifică rândurile analizate. Nimic nu se salvează în registrul tău până nu confirmi.',

    'already_imported' => 'Acest fișier a fost deja importat.',

    'already_imported_link' => 'Vezi rezultatul importului',

    'expired_html' => 'Previzualizarea a expirat. <a href="/imports/new" class="underline">Încarcă fișierul din nou</a> pentru a reîncerca.',
    'unreadable_html' => 'Previzualizarea nu poate fi citită. <a href="/imports/new" class="underline">Încarcă fișierul din nou</a> pentru a reîncerca.',

    'save_name' => 'Salvează numele',
    'account_name_label' => 'Numele contului',
    'account_placeholder' => 'de ex. Cont principal de economii',
    'rename_aria' => 'Redenumește această contraparte',

    'unknown_iban_prefix' => 'Am găsit un IBAN necunoscut:',

    'unknown_account_prefix' => 'Am găsit un cont necunoscut:',
    'unknown_iban_suffix' => 'Denumește acest cont.',

    'ics' => [
        'name' => 'Card ICS',
        'heading' => 'Denumește-ți contul de card ICS.',
        'help' => 'Este prima dată când imporți date ICS. Dă-i un nume acestui card, ca să apară consecvent în toată aplicația.',
        'placeholder' => 'de ex. Card ICS',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Denumește-ți contul PayPal.',
        'help' => 'Este prima dată când imporți date PayPal. Dă-i un nume acestui portofel, ca să apară consecvent în toată aplicația.',
        'placeholder' => 'de ex. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Denumește-ți contul Google Play.',
        'help' => 'Este prima dată când imporți o chitanță Google Play. Dă-i un nume acestui cont, ca să apară consecvent în toată aplicația.',
        'placeholder' => 'de ex. Google Play',
    ],

    'col_date' => 'Dată',
    'col_funding_source' => 'Sursă de finanțare',
    'col_counterparty' => 'Contraparte',
    'col_amount' => 'Sumă',
    'col_status' => 'Stare',

    'status' => [
        'new' => 'Nouă',
        'new_title' => 'Va fi adăugată în registrul tău.',
        'duplicate' => 'Duplicat',
        'duplicate_title' => 'Deja importată — va fi omisă.',
        'enriched' => 'Îmbogățită',
        'enriched_title' => 'Rândul existent va fi actualizat cu o referință de sursă mai puternică.',
        'error' => 'Eroare',
    ],

    'rows_shown' => 'Rânduri afișate: :shown din :total',

    'show_more' => 'Afișează mai multe rânduri',

    'errors' => [
        'app_locked' => 'Deblocați aplicația pentru a importa: cheile de criptare nu pot fi folosite cât timp este blocată.',
        'archive_holds_one_message' => 'Acest fișier este un singur mesaj de e-mail, nu o arhivă de căsuță poștală, deci citit ca arhivă nu are nimic în el. Încarcă-l din nou cu formatul Mesaj de e-mail.',
        'email_file_is_an_archive' => 'Acest fișier este o arhivă de căsuță poștală: conține mai mult de un mesaj, iar citit ca un singur mesaj ar lua doar primul. Încarcă-l din nou cu formatul Arhivă de căsuță poștală.',
        'file_stopped_short' => 'Rândul de antet se potrivea, deci formatul este corect. Citirea s-a oprit înainte de sfârșitul fișierului. O singură linie ilizibilă face asta, la fel și un fișier prea mare pentru acest dispozitiv. Încearcă o perioadă mai scurtă.',
        'file_unreadable' => 'Acest fișier nu a putut fi citit.',
        'file_unreadable_detail' => 'Aplicația nu a putut citi acest fișier (:code). Detaliile complete se află în jurnalul aplicației; menționați acest cod dacă raportați o problemă.',
        'iban_not_in_preview' => 'Acest IBAN nu face parte din previzualizarea curentă.',
        'not_an_email_file' => 'Acest fișier nu este nici mesaj de e-mail, nici arhivă de căsuță poștală, deci nu are ce să fie citit în el ca bon. Alege tipul de import și formatul care se potrivesc fișierului tău.',
        'pdf_has_no_text_layer' => 'Acest PDF nu conține text — este o scanare sau o fotografie a unui extras, deci nu are ce să fie citit în el. Descarcă extrasul propriu-zis de la bancă sau folosește un export CSV.',
        'pdf_password_protected' => 'Acest PDF este protejat cu parolă, așa că niciun cititor nu îl poate deschide. Salvează o copie neprotejată din vizualizatorul tău de PDF și importă copia aceea.',
        'pdf_reader_unavailable' => 'Această versiune a aplicației nu are niciun cititor PDF, așa că un extras PDF nu poate fi deschis aici. Importă acest fișier pe alt dispozitiv sau folosește un export CSV de la bancă.',
        'row_belongs_to_another_statement' => 'Acest rând aparține unei tranzacții dintr-un alt fișier de extras. Importați și acel extras — cele două sunt citite împreună.',
        'row_unreadable' => 'Acest rând nu a putut fi citit.',
        'row_unreadable_detail' => 'Aplicația nu a putut citi acest rând (:code). Detaliile complete se află în jurnalul aplicației; menționați acest cod dacă raportați o problemă.',
        'unknown_account' => 'Acest rând aparține unui cont căruia nu i-ai dat încă un nume.',
    ],

    'receipts' => [
        'heading' => 'Acest fișier a fost citit ca e-mail',
        'saved' => 'Ce conținea este listat mai jos, iar fiecare mesaj a fost păstrat.',
        'none_imported' => 'Nimic din toate acestea nu a devenit tranzacție, așa că în registrul tău nu s-a adăugat nimic.',
        'shown' => 'Mesaje afișate: :shown din :total',
        'no_subject' => 'Fără subiect',

        'state' => [
            'read' => 'Citit ca plată — confirmă acest import ca să ajungă în registrul tău.',
            'not_a_payment' => 'Nu este o plată. Acest mesaj anunță ceva în loc să confirme o plată.',
            'unreadable' => 'Păstrat. Aplicația citește bonuri de la acest expeditor, dar nu a găsit suma, comerciantul și referința în acest mesaj.',
            'unknown_sender' => 'Păstrat. Aplicația nu citește bonuri de la acest expeditor, așa că nu a luat nimic din mesaj.',
        ],
    ],

    'failed' => [
        'heading' => 'Acest fișier nu a putut fi citit',
        'no_rows' => 'Nu au fost găsite tranzacții în acest fișier, deci nu este nimic de importat.',
        'nothing_read' => 'Nimic din acest fișier nu a putut fi citit ca tranzacție, deci nu este nimic de importat.',
        'every_row' => 'Niciun rând din acest fișier nu a putut fi citit, deci nu este nimic de importat. Fiecare rând este listat mai jos cu motivul.',
        'likely_cause' => 'De obicei rândul de antet nu corespunde sursei alese. Verifică banca și formatul pe ecranul de încărcare sau descarcă din nou extrasul de la banca ta.',
        'truncated_heading' => 'Din acest fișier a putut fi citită doar o parte',
        'truncated' => 'Citirea s-a oprit la jumătatea fișierului. Acest fișier nu poate fi importat: salvarea doar a părții citite ar lăsa restul perioadei lipsă, fără nimic care să o semnaleze.',
        'truncated_action' => 'Încarcă fișierul din nou sau descarcă o copie nouă a extrasului de la banca ta.',
        'some_rows' => 'Unele rânduri nu au putut fi citite. Sunt marcate mai jos și vor fi sărite; confirmarea importă restul.',
        'detail_label' => 'Ce a raportat analizorul:',
        'rows_read_label' => 'Rânduri citite',
        'rows_skipped_label' => 'Rânduri sărite',
    ],
];
