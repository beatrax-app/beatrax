<?php

declare(strict_types=1);

return [
    'page_title' => 'Peržiūrėti importą',
    'heading' => 'Peržiūrėti importą',
    'discard' => 'Atmesti importą',
    'confirm' => 'Patvirtinti importą',
    'subtitle' => 'Peržiūrėk nuskaitytas eilutes. Kol nepatvirtinsi, į didžiąją knygą nieko neįrašoma.',

    'expired_html' => 'Peržiūros galiojimas baigėsi. <a href="/imports/new" class="underline">Įkelk failą iš naujo</a> ir bandyk dar kartą.',

    'save_name' => 'Išsaugoti pavadinimą',
    'account_name_label' => 'Sąskaitos pavadinimas',
    'account_placeholder' => 'pvz. Pagrindinė taupomoji sąskaita',
    'rename_aria' => 'Pervadinti šią kitą šalį',

    'unknown_iban_prefix' => 'Radome nepažįstamą IBAN:',
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
        'unknown_error' => 'įvyko nežinoma klaida',
        'open_horizon' => 'Atidaryti Horizon',
        'failed_suffix' => 'ir pakartok arba patikrink.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'Šis IBAN nėra dabartinės peržiūros dalis.',
    ],
];
