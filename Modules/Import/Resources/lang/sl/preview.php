<?php

declare(strict_types=1);

return [
    'page_title' => 'Predogled uvoza',
    'heading' => 'Predogled uvoza',
    'discard' => 'Zavrzi uvoz',
    'confirm' => 'Potrdi uvoz',
    'subtitle' => 'Preglej razčlenjene vrstice. Nič se ne shrani v tvojo glavno knjigo, dokler ne potrdiš.',

    'already_imported' => 'Ta datoteka je že uvožena.',

    'already_imported_link' => 'Poglej rezultat uvoza',

    'expired_html' => 'Predogled je potekel. <a href="/imports/new" class="underline">Znova naloži datoteko</a> in poskusi še enkrat.',

    'save_name' => 'Shrani ime',
    'account_name_label' => 'Ime računa',
    'account_placeholder' => 'npr. Glavni varčevalni račun',
    'rename_aria' => 'Preimenuj to nasprotno stranko',

    'unknown_iban_prefix' => 'Našli smo neznan IBAN:',

    'unknown_account_prefix' => 'Našli smo neznan račun:',
    'unknown_iban_suffix' => 'Poimenuj ta račun.',

    'ics' => [
        'heading' => 'Poimenuj svoj kartični račun ICS.',
        'help' => 'To je prvič, da uvažaš podatke ICS. Poimenuj to kartico, da se bo dosledno prikazovala po vsej aplikaciji.',
        'placeholder' => 'npr. kartica ICS',
    ],

    'paypal' => [
        'heading' => 'Poimenuj svoj račun PayPal.',
        'help' => 'To je prvič, da uvažaš podatke PayPal. Poimenuj to denarnico, da se bo dosledno prikazovala po vsej aplikaciji.',
        'placeholder' => 'npr. PayPal',
    ],

    'col_date' => 'Datum',
    'col_funding_source' => 'Vir sredstev',
    'col_counterparty' => 'Nasprotna stranka',
    'col_amount' => 'Znesek',
    'col_status' => 'Stanje',

    'status' => [
        'new' => 'Novo',
        'new_title' => 'Dodano bo v tvojo glavno knjigo.',
        'duplicate' => 'Podvojeno',
        'duplicate_title' => 'Že uvoženo — preskočeno bo.',
        'enriched' => 'Obogateno',
        'enriched_title' => 'Obstoječa vrstica bo posodobljena z močnejšim sklicem na vir.',
        'error' => 'Napaka',
    ],

    'chain' => [
        'heading' => 'Razreševanje verig…',
        'pending' => 'V čakalni vrsti. Razreševanje verig se bo kmalu začelo.',
        'running' => 'Povezovanje verig financiranja in razstavljanje poravnav z izpiska.',
        'failed_prefix' => 'Razreševanje verig ni uspelo:',
        'failed_detail' => 'podrobnosti so v dnevniku opravil',
        'open_horizon' => 'Odpri Horizon',
        'failed_suffix' => 'za ponovni poskus ali pregled.',
    ],

    'errors' => [
        'app_locked' => 'Odklenite aplikacijo za uvoz: šifrirnih ključev ni mogoče uporabiti, dokler je zaklenjena.',
        'file_unreadable' => 'Te datoteke ni bilo mogoče prebrati.',
        'iban_not_in_preview' => 'Ta IBAN ni del trenutnega predogleda.',
        'pdf_reader_unavailable' => 'Izpiski v PDF potrebujejo program pdftotext, ki tukaj ni nameščen. Uvozi to datoteko na računalniku, kjer je, ali pa raje uporabi izvoz CSV iz banke.',
        'row_unreadable' => 'Te vrstice ni bilo mogoče prebrati.',
        'unknown_account' => 'Ta vrstica pripada računu, ki ga še nisi poimenoval.',
    ],

    'failed' => [
        'heading' => 'Te datoteke ni bilo mogoče prebrati',
        'no_rows' => 'V tej datoteki ni bilo najdenih transakcij, zato ni kaj uvoziti.',
        'nothing_read' => 'Ničesar v tej datoteki ni bilo mogoče prebrati kot transakcijo, zato ni kaj uvoziti.',
        'every_row' => 'Nobene vrstice v tej datoteki ni bilo mogoče prebrati, zato ni kaj uvoziti. Vsaka vrstica je spodaj skupaj z razlogom.',
        'likely_cause' => 'Običajno glava ne ustreza viru, ki si ga izbral. Preveri banko in obliko na zaslonu za nalaganje ali izpisek pri banki prenesi znova.',
        'truncated_heading' => 'Iz te datoteke je bilo mogoče prebrati le del',
        'truncated' => 'Branje se je ustavilo sredi datoteke. Vse za to točko ni bilo prebrano in ne bo uvoženo.',
        'some_rows' => 'Nekaterih vrstic ni bilo mogoče prebrati. Spodaj so označene in bodo preskočene; s potrditvijo se uvozi ostalo.',
        'detail_label' => 'Kaj je sporočil razčlenjevalnik:',
        'rows_read_label' => 'Prebrane vrstice',
        'rows_skipped_label' => 'Preskočene vrstice',
    ],
];
