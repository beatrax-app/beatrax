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
    'unreadable_html' => 'Predogleda ni mogoče prebrati. <a href="/imports/new" class="underline">Znova naloži datoteko</a> in poskusi še enkrat.',

    'save_name' => 'Shrani ime',
    'account_name_label' => 'Ime računa',
    'account_placeholder' => 'npr. Glavni varčevalni račun',
    'rename_aria' => 'Preimenuj to nasprotno stranko',

    'unknown_iban_prefix' => 'Našli smo neznan IBAN:',

    'unknown_account_prefix' => 'Našli smo neznan račun:',
    'unknown_iban_suffix' => 'Poimenuj ta račun.',

    'ics' => [
        'name' => 'kartica ICS',
        'heading' => 'Poimenuj svoj kartični račun ICS.',
        'help' => 'To je prvič, da uvažaš podatke ICS. Poimenuj to kartico, da se bo dosledno prikazovala po vsej aplikaciji.',
        'placeholder' => 'npr. kartica ICS',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Poimenuj svoj račun PayPal.',
        'help' => 'To je prvič, da uvažaš podatke PayPal. Poimenuj to denarnico, da se bo dosledno prikazovala po vsej aplikaciji.',
        'placeholder' => 'npr. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Poimenuj svoj račun Google Play.',
        'help' => 'To je prvič, da uvažaš potrdilo Google Play. Poimenuj ta račun, da se bo dosledno prikazoval po vsej aplikaciji.',
        'placeholder' => 'npr. Google Play',
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

    'rows_shown' => 'Prikazane vrstice: :shown od :total',

    'show_more' => 'Pokaži več vrstic',

    'errors' => [
        'app_locked' => 'Odklenite aplikacijo za uvoz: šifrirnih ključev ni mogoče uporabiti, dokler je zaklenjena.',
        'archive_holds_one_message' => 'Ta datoteka je eno samo e-poštno sporočilo, ne arhiv nabiralnika, zato v njej kot arhivu ni ničesar. Naloži jo znova z obliko E-poštno sporočilo.',
        'email_file_is_an_archive' => 'Ta datoteka je arhiv nabiralnika: vsebuje več kot eno sporočilo, prebrana kot eno sporočilo pa bi vzela le prvo. Naloži jo znova z obliko Arhiv nabiralnika.',
        'file_stopped_short' => 'Glava se je ujemala, zato je oblika prava. Branje se je ustavilo pred koncem datoteke. To povzroči ena neberljiva vrstica, prav tako datoteka, ki je prevelika za to napravo. Poskusi s krajšim obdobjem.',
        'file_unreadable' => 'Te datoteke ni bilo mogoče prebrati.',
        'file_unreadable_detail' => 'Aplikacija te datoteke ni mogla prebrati (:code). Vse podrobnosti so v dnevniku aplikacije; pri prijavi težave navedite to kodo.',
        'iban_not_in_preview' => 'Ta IBAN ni del trenutnega predogleda.',
        'not_an_email_file' => 'Ta datoteka ni ne e-poštno sporočilo ne arhiv nabiralnika, zato v njej ni ničesar za branje kot potrdilo. Izberi vrsto uvoza in obliko, ki se ujemata s tvojo datoteko.',
        'pdf_has_no_text_layer' => 'Ta PDF ne vsebuje besedila — gre za skeniran izpisek ali njegovo fotografijo, zato v njem ni ničesar za branje. Prenesi sam izpisek pri svoji banki ali pa uporabi izvoz CSV.',
        'pdf_password_protected' => 'Ta PDF je zaščiten z geslom, zato ga ne odpre noben bralnik. V pregledovalniku PDF shrani nezaščiteno kopijo in uvozi njo.',
        'pdf_reader_unavailable' => 'Ta različica aplikacije nima nobenega bralnika PDF, zato izpiska v PDF tu ni mogoče odpreti. Uvozi to datoteko na drugi napravi ali pa raje uporabi izvoz CSV iz banke.',
        'row_belongs_to_another_statement' => 'Ta vrstica pripada transakciji v drugi datoteki izpiska. Uvozite tudi ta izpisek — oba se bereta skupaj.',
        'row_unreadable' => 'Te vrstice ni bilo mogoče prebrati.',
        'row_unreadable_detail' => 'Aplikacija te vrstice ni mogla prebrati (:code). Vse podrobnosti so v dnevniku aplikacije; pri prijavi težave navedite to kodo.',
        'unknown_account' => 'Ta vrstica pripada računu, ki ga še nisi poimenoval.',
    ],

    'receipts' => [
        'heading' => 'Ta datoteka je bila prebrana kot e-pošta',
        'saved' => 'Kaj je vsebovala, je našteto spodaj, in vsako sporočilo je shranjeno.',
        'none_imported' => 'Nič od tega ni postalo transakcija, zato v tvojo glavno knjigo ni bilo dodano nič.',
        'shown' => 'Prikazana sporočila: :shown od :total',
        'no_subject' => 'Brez zadeve',

        'state' => [
            'read' => 'Prebrano kot plačilo — potrdi ta uvoz, da pride v tvojo glavno knjigo.',
            'not_a_payment' => 'Ni plačilo. To sporočilo nekaj naznanja, namesto da bi potrdilo plačilo.',
            'unreadable' => 'Shranjeno. Aplikacija bere račune tega pošiljatelja, a v tem sporočilu ni našla zneska, trgovca in sklica.',
            'unknown_sender' => 'Shranjeno. Aplikacija ne bere računov tega pošiljatelja, zato iz sporočila ni vzela ničesar.',
        ],
    ],

    'failed' => [
        'heading' => 'Te datoteke ni bilo mogoče prebrati',
        'no_rows' => 'V tej datoteki ni bilo najdenih transakcij, zato ni kaj uvoziti.',
        'nothing_read' => 'Ničesar v tej datoteki ni bilo mogoče prebrati kot transakcijo, zato ni kaj uvoziti.',
        'every_row' => 'Nobene vrstice v tej datoteki ni bilo mogoče prebrati, zato ni kaj uvoziti. Vsaka vrstica je spodaj skupaj z razlogom.',
        'likely_cause' => 'Običajno glava ne ustreza viru, ki si ga izbral. Preveri banko in obliko na zaslonu za nalaganje ali izpisek pri banki prenesi znova.',
        'truncated_heading' => 'Iz te datoteke je bilo mogoče prebrati le del',
        'truncated' => 'Branje se je ustavilo sredi datoteke. Te datoteke ni mogoče uvoziti: če bi shranili samo prebrani del, bi preostanek obdobja manjkal, ne da bi to karkoli označilo.',
        'truncated_action' => 'Datoteko naložite znova ali prenesite novo kopijo izpiska pri svoji banki.',
        'some_rows' => 'Nekaterih vrstic ni bilo mogoče prebrati. Spodaj so označene in bodo preskočene; s potrditvijo se uvozi ostalo.',
        'detail_label' => 'Kaj je sporočil razčlenjevalnik:',
        'rows_read_label' => 'Prebrane vrstice',
        'rows_skipped_label' => 'Preskočene vrstice',
    ],
];
