<?php

declare(strict_types=1);

return [
    'page_title' => 'Previzualizează importul',
    'heading' => 'Previzualizează importul',
    'discard' => 'Renunță la import',
    'confirm' => 'Confirmă importul',
    'subtitle' => 'Verifică rândurile analizate. Nimic nu se salvează în registrul tău până nu confirmi.',

    'expired_html' => 'Previzualizarea a expirat. <a href="/imports/new" class="underline">Încarcă fișierul din nou</a> pentru a reîncerca.',

    'save_name' => 'Salvează numele',
    'account_name_label' => 'Numele contului',
    'account_placeholder' => 'de ex. Cont principal de economii',
    'rename_aria' => 'Redenumește această contraparte',

    'unknown_iban_prefix' => 'Am găsit un IBAN necunoscut:',
    'unknown_iban_suffix' => 'Denumește acest cont.',

    'ics' => [
        'heading' => 'Denumește-ți contul de card ICS.',
        'help' => 'Este prima dată când imporți date ICS. Dă-i un nume acestui card, ca să apară consecvent în toată aplicația.',
        'placeholder' => 'de ex. Card ICS',
    ],

    'paypal' => [
        'heading' => 'Denumește-ți contul PayPal.',
        'help' => 'Este prima dată când imporți date PayPal. Dă-i un nume acestui portofel, ca să apară consecvent în toată aplicația.',
        'placeholder' => 'de ex. PayPal',
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

    'chain' => [
        'heading' => 'Se rezolvă lanțurile…',
        'pending' => 'În coadă. Rezolvarea lanțurilor va începe în curând.',
        'running' => 'Se leagă lanțurile de finanțare și se descompun decontările din extras.',
        'failed_prefix' => 'Rezolvarea lanțurilor a eșuat:',
        'failed_detail' => 'detaliile sunt în jurnalul de sarcini',
        'open_horizon' => 'Deschide Horizon',
        'failed_suffix' => 'pentru a reîncerca sau a inspecta.',
    ],

    'errors' => [
        'app_locked' => 'Deblocați aplicația pentru a importa: cheia comerciantului nu poate fi calculată cât timp este blocată.',
        'file_unreadable' => 'Acest fișier nu a putut fi citit.',
        'iban_not_in_preview' => 'Acest IBAN nu face parte din previzualizarea curentă.',
        'row_unreadable' => 'Acest rând nu a putut fi citit.',
        'unknown_account' => 'Acest rând aparține unui cont căruia nu i-ai dat încă un nume.',
    ],

    'failed' => [
        'heading' => 'Acest fișier nu a putut fi citit',
        'no_rows' => 'Nu au fost găsite tranzacții în acest fișier, deci nu este nimic de importat.',
        'nothing_read' => 'Nimic din acest fișier nu a putut fi citit ca tranzacție, deci nu este nimic de importat.',
        'every_row' => 'Niciun rând din acest fișier nu a putut fi citit, deci nu este nimic de importat. Fiecare rând este listat mai jos cu motivul.',
        'likely_cause' => 'De obicei rândul de antet nu corespunde sursei alese. Verifică banca și formatul pe ecranul de încărcare sau descarcă din nou extrasul de la banca ta.',
        'truncated_heading' => 'Din acest fișier a putut fi citită doar o parte',
        'truncated' => 'Citirea s-a oprit la jumătatea fișierului. Tot ce urmează nu a fost citit și nu va fi importat.',
        'some_rows' => 'Unele rânduri nu au putut fi citite. Sunt marcate mai jos și vor fi sărite; confirmarea importă restul.',
        'detail_label' => 'Ce a raportat analizorul:',
        'rows_read_label' => 'Rânduri citite',
        'rows_skipped_label' => 'Rânduri sărite',
    ],
];
