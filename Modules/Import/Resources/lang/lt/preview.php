<?php

declare(strict_types=1);

return [
    'page_title' => 'Peržiūrėti importą',
    'heading' => 'Peržiūrėti importą',
    'discard' => 'Atmesti importą',
    'confirm' => 'Patvirtinti importą',
    'subtitle' => 'Peržiūrėk nuskaitytas eilutes. Kol nepatvirtinsi, į didžiąją knygą nieko neįrašoma.',

    'already_imported' => 'Šis failas jau importuotas.',

    'already_imported_link' => 'Peržiūrėti importo rezultatą',

    'expired_html' => 'Peržiūros galiojimas baigėsi. <a href="/imports/new" class="underline">Įkelk failą iš naujo</a> ir bandyk dar kartą.',

    'save_name' => 'Išsaugoti pavadinimą',
    'account_name_label' => 'Sąskaitos pavadinimas',
    'account_placeholder' => 'pvz. Pagrindinė taupomoji sąskaita',
    'rename_aria' => 'Pervadinti šią kitą šalį',

    'unknown_iban_prefix' => 'Radome nepažįstamą IBAN:',

    'unknown_account_prefix' => 'Radome nepažįstamą sąskaitą:',
    'unknown_iban_suffix' => 'Pavadink šią sąskaitą.',

    'ics' => [
        'heading' => 'Pavadink savo ICS kortelės sąskaitą.',
        'help' => 'ICS duomenis importuoji pirmą kartą. Suteik šiai kortelei pavadinimą, kad ji visoje programėlėje būtų rodoma vienodai.',
        'placeholder' => 'pvz. ICS kortelė',
    ],

    'paypal' => [
        'heading' => 'Pavadink savo PayPal sąskaitą.',
        'help' => 'PayPal duomenis importuoji pirmą kartą. Suteik šiai piniginei pavadinimą, kad ji visoje programėlėje būtų rodoma vienodai.',
        'placeholder' => 'pvz. PayPal',
    ],

    'col_date' => 'Data',
    'col_funding_source' => 'Lėšų šaltinis',
    'col_counterparty' => 'Kita šalis',
    'col_amount' => 'Suma',
    'col_status' => 'Būsena',

    'status' => [
        'new' => 'Nauja',
        'new_title' => 'Bus įtraukta į didžiąją knygą.',
        'duplicate' => 'Dublikatas',
        'duplicate_title' => 'Jau importuota — bus praleista.',
        'enriched' => 'Papildyta',
        'enriched_title' => 'Esama eilutė bus atnaujinta tikslesne šaltinio nuoroda.',
        'error' => 'Klaida',
    ],

    'chain' => [
        'heading' => 'Nustatomos grandinės…',
        'pending' => 'Eilėje. Grandinių nustatymas netrukus prasidės.',
        'running' => 'Siejamos lėšų grandinės ir skaidomi išrašo atsiskaitymai.',
        'failed_prefix' => 'Grandinių nustatyti nepavyko:',
        'failed_detail' => 'išsamesnė informacija yra užduočių žurnale',
        'open_horizon' => 'Atidaryti Horizon',
        'failed_suffix' => 'ir pakartok arba patikrink.',
    ],

    'errors' => [
        'app_locked' => 'Atrakinkite programėlę, kad importuotumėte: šifravimo raktų negalima naudoti, kol ji užrakinta.',
        'file_unreadable' => 'Šio failo nepavyko perskaityti.',
        'iban_not_in_preview' => 'Šis IBAN nėra dabartinės peržiūros dalis.',
        'pdf_reader_unavailable' => 'PDF išrašams reikia programos pdftotext, kuri čia neįdiegta. Importuok šį failą kompiuteryje, kuriame ji yra, arba naudok banko CSV eksportą.',
        'row_unreadable' => 'Šios eilutės nepavyko perskaityti.',
        'unknown_account' => 'Ši eilutė priklauso sąskaitai, kuriai dar nesuteikei pavadinimo.',
    ],

    'failed' => [
        'heading' => 'Nepavyko perskaityti šio failo',
        'no_rows' => 'Šiame faile operacijų nerasta, todėl nėra ką importuoti.',
        'nothing_read' => 'Nieko šiame faile nepavyko perskaityti kaip operacijos, todėl nėra ką importuoti.',
        'every_row' => 'Nepavyko perskaityti nė vienos šio failo eilutės, todėl nėra ką importuoti. Kiekviena eilutė su priežastimi nurodyta žemiau.',
        'likely_cause' => 'Dažniausiai antraštės eilutė neatitinka pasirinkto šaltinio. Patikrink banką ir formatą įkėlimo ekrane arba iš naujo atsisiųsk išrašą iš savo banko.',
        'truncated_heading' => 'Iš šio failo pavyko perskaityti tik dalį',
        'truncated' => 'Skaitymas sustojo failo viduryje. Viskas po to nebuvo perskaityta ir nebus importuota.',
        'some_rows' => 'Kai kurių eilučių nepavyko perskaityti. Jos pažymėtos žemiau ir bus praleistos; patvirtinus importuojama kita dalis.',
        'detail_label' => 'Ką pranešė analizatorius:',
        'rows_read_label' => 'Perskaitytos eilutės',
        'rows_skipped_label' => 'Praleistos eilutės',
    ],
];
