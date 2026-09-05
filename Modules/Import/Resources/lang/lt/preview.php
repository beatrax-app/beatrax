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
    'unreadable_html' => 'Peržiūros nepavyksta perskaityti. <a href="/imports/new" class="underline">Įkelk failą iš naujo</a> ir bandyk dar kartą.',

    'save_name' => 'Išsaugoti pavadinimą',
    'account_name_label' => 'Sąskaitos pavadinimas',
    'account_placeholder' => 'pvz. Pagrindinė taupomoji sąskaita',
    'rename_aria' => 'Pervadinti šią kitą šalį',

    'unknown_iban_prefix' => 'Radome nepažįstamą IBAN:',

    'unknown_account_prefix' => 'Radome nepažįstamą sąskaitą:',
    'unknown_iban_suffix' => 'Pavadink šią sąskaitą.',

    'ics' => [
        'name' => 'ICS kortelė',
        'heading' => 'Pavadink savo ICS kortelės sąskaitą.',
        'help' => 'ICS duomenis importuoji pirmą kartą. Suteik šiai kortelei pavadinimą, kad ji visoje programėlėje būtų rodoma vienodai.',
        'placeholder' => 'pvz. ICS kortelė',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Pavadink savo PayPal sąskaitą.',
        'help' => 'PayPal duomenis importuoji pirmą kartą. Suteik šiai piniginei pavadinimą, kad ji visoje programėlėje būtų rodoma vienodai.',
        'placeholder' => 'pvz. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Pavadink savo Google Play paskyrą.',
        'help' => 'Google Play kvitą importuoji pirmą kartą. Suteik šiai paskyrai pavadinimą, kad ji visoje programėlėje būtų rodoma vienodai.',
        'placeholder' => 'pvz. Google Play',
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

    'rows_shown' => 'Rodomos eilutės: :shown iš :total',

    'show_more' => 'Rodyti daugiau eilučių',

    'errors' => [
        'app_locked' => 'Atrakinkite programėlę, kad importuotumėte: šifravimo raktų negalima naudoti, kol ji užrakinta.',
        'archive_holds_one_message' => 'Šis failas yra vienas el. laiškas, o ne pašto dėžutės archyvas, tad perskaitytas kaip archyvas jis nieko neturi. Įkelk jį dar kartą su formatu El. laiškas.',
        'email_file_is_an_archive' => 'Šis failas yra pašto dėžutės archyvas: jame daugiau nei vienas laiškas, o perskaitytas kaip vienas laiškas jis paimtų tik pirmąjį. Įkelk jį dar kartą su formatu Pašto dėžutės archyvas.',
        'file_stopped_short' => 'Antraštės eilutė atitiko, tad formatas teisingas. Skaitymas sustojo nepasiekęs failo pabaigos. Taip nutinka dėl vienos neperskaitomos eilutės arba dėl šiam įrenginiui per didelio failo. Pabandyk trumpesnį laikotarpį.',
        'file_unreadable' => 'Šio failo nepavyko perskaityti.',
        'file_unreadable_detail' => 'Programai nepavyko perskaityti šio failo (:code). Visa informacija yra programos žurnale; pranešdami apie problemą nurodykite šį kodą.',
        'iban_not_in_preview' => 'Šis IBAN nėra dabartinės peržiūros dalis.',
        'message_unreadable' => 'Šio laiško nepavyko perskaityti, todėl jis buvo praleistas.',
        'not_an_email_file' => 'Šis failas nėra nei el. laiškas, nei pašto dėžutės archyvas, tad jame nėra ko skaityti kaip kvito. Pasirink importo tipą ir formatą, atitinkančius tavo failą.',
        'pdf_has_no_text_layer' => 'Šiame PDF nėra teksto — tai išrašo nuskaitymas arba nuotrauka, tad jame nėra ko skaityti. Atsisiųsk patį išrašą iš savo banko arba naudok CSV eksportą.',
        'pdf_password_protected' => 'Šis PDF apsaugotas slaptažodžiu, tad jo neatidarys nė viena skaityklė. Savo PDF peržiūros programoje išsaugok neapsaugotą kopiją ir importuok ją.',
        'pdf_reader_unavailable' => 'Ši programos versija visai neturi PDF skaitytuvo, todėl PDF išrašo čia atidaryti nepavyks. Importuok šį failą kitame įrenginyje arba naudok banko CSV eksportą.',
        'row_belongs_to_another_statement' => 'Ši eilutė priklauso operacijai kitame išrašo faile. Importuokite ir tą išrašą — abu skaitomi kartu.',
        'row_unreadable' => 'Šios eilutės nepavyko perskaityti.',
        'row_unreadable_detail' => 'Programai nepavyko perskaityti šios eilutės (:code). Visa informacija yra programos žurnale; pranešdami apie problemą nurodykite šį kodą.',
        'unknown_account' => 'Ši eilutė priklauso sąskaitai, kuriai dar nesuteikei pavadinimo.',
    ],

    'refused' => [
        'accounts_to_name' => 'Šis failas laukia, kol suteiksi pavadinimą sąskaitai, kuriai priklauso jo eilutės.',
        'file_did_not_read_in_full' => 'Šio failo nepavyko perskaityti iki galo.',
        'nothing_importable' => 'Iš šio failo nieko negalima importuoti.',
        'preview_expired' => 'Šio failo peržiūra per sena, kad būtų galima dabar išsaugoti. Įkelk jį iš naujo.',
    ],

    'receipts' => [
        'heading' => 'Šis failas perskaitytas kaip el. laiškas',
        'saved' => 'Kas jame buvo, surašyta žemiau, o kiekvienas laiškas išsaugotas.',
        'none_imported' => 'Nė vienas iš jų netapo operacija, todėl į didžiąją knygą nieko neįrašyta.',
        'shown' => 'Rodomi laiškai: :shown iš :total',
        'no_subject' => 'Be temos',

        'state' => [
            'read' => 'Perskaityta kaip mokėjimas — patvirtink šį importą, kad jis patektų į didžiąją knygą.',
            'not_a_payment' => 'Tai ne mokėjimas. Šis laiškas apie kažką praneša, o ne patvirtina mokėjimą.',
            'unreadable' => 'Išsaugota. Programa skaito šio siuntėjo kvitus, bet šiame laiške nerado nei sumos, nei prekybininko, nei nuorodos.',
            'unknown_sender' => 'Išsaugota. Programa neskaito šio siuntėjo kvitų, todėl iš laiško nieko nepaėmė.',
        ],
    ],

    'failed' => [
        'heading' => 'Nepavyko perskaityti šio failo',
        'no_rows' => 'Šiame faile operacijų nerasta, todėl nėra ką importuoti.',
        'nothing_read' => 'Nieko šiame faile nepavyko perskaityti kaip operacijos, todėl nėra ką importuoti.',
        'every_row' => 'Nepavyko perskaityti nė vienos šio failo eilutės, todėl nėra ką importuoti. Kiekviena eilutė su priežastimi nurodyta žemiau.',
        'likely_cause' => 'Dažniausiai antraštės eilutė neatitinka pasirinkto šaltinio. Patikrink banką ir formatą įkėlimo ekrane arba iš naujo atsisiųsk išrašą iš savo banko.',
        'truncated_heading' => 'Iš šio failo pavyko perskaityti tik dalį',
        'truncated' => 'Skaitymas sustojo failo viduryje. Šio failo importuoti negalima: išsaugojus tik perskaitytą dalį, likusi laikotarpio dalis liktų neįtraukta ir niekas to nenurodytų.',
        'truncated_action' => 'Įkelkite failą dar kartą arba atsisiųskite naują išrašo kopiją iš savo banko.',
        'some_rows' => 'Kai kurių eilučių nepavyko perskaityti. Jos pažymėtos žemiau ir bus praleistos; patvirtinus importuojama kita dalis.',
        'detail_label' => 'Ką pranešė analizatorius:',
        'rows_read_label' => 'Perskaitytos eilutės',
        'rows_skipped_label' => 'Praleistos eilutės',
    ],
];
