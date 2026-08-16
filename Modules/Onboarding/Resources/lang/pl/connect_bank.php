<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Twój bank',
    'h1' => 'Pobierz wyciąg i upuść go poniżej',
    'lede' => 'Wybierz format, w jakim bank udostępnił wyciąg, i upuść plik. CAMT.053 i MT940 wykrywamy automatycznie.',

    'format_group_aria' => 'Format wyciągu bankowego',
    'got_it_as' => 'Mam go jako:',
    'badge_recommended' => 'zalecane',

    'mini' => [
        'login_label' => 'Zaloguj się',
        'login_sub' => 'Strona Twojego banku',
        'statements_label' => 'Otwórz wyciągi',
        'statements_sub' => 'W menu banku',
        'range_label' => 'Wybierz zakres',
        'range_sub' => 'Ostatnie 90 dni',
        'download_label' => 'Pobierz',
    ],

    'csv_picker_aria' => 'Który bank wyeksportował Twój plik CSV?',
    'csv_picker_from' => 'Z banku:',

    'drop_lead_camt053' => 'Upuść tutaj plik CAMT.053',
    'drop_lead_mt940' => 'Upuść tutaj plik MT940',
    'drop_lead_asn' => 'Upuść tutaj plik CSV z ASN',
    'drop_lead_ing' => 'Upuść tutaj plik CSV z ING',
    'drop_lead_pick_bank' => 'Wybierz bank, który wyeksportował Twój plik CSV — bez tego nie odczytamy go poprawnie.',
    'drop_lead_default' => 'Upuść tutaj plik wyciągu',
    'browse_file' => 'lub wybierz plik z dysku',

    'banks_mt940' => 'Obsługiwane: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Obsługiwane: ASN, ING — kolejne formaty pojawią się wraz z próbkami od użytkowników.',
    'banks_default' => 'Obsługiwane: ASN, ING',

    'file_ready' => '· ✓ gotowe',

    'skip' => 'Pomiń ten krok',
    'continue' => 'Dalej →',

    'errors' => [
        'file_required' => 'Najpierw upuść plik wyciągu w polu powyżej.',
        'file_max' => 'Ten plik jest za duży. Upuść wyciąg mniejszy niż 10 MB.',
        'file_extensions' => 'Ten plik nie wygląda na wyciąg bankowy. Upuść plik CAMT.053 XML, CSV lub MT940.',
        'pick_bank' => 'Zanim przejdziesz dalej, wybierz bank, który wyeksportował Twój plik CSV.',
        'unreadable' => 'Nie udało się odczytać tego pliku. Pełny błąd znajdziesz w /dev/logs.',
    ],
];
