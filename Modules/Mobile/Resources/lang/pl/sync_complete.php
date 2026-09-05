<?php

declare(strict_types=1);

return [
    'page_title' => 'To urządzenie jest zsynchronizowane',
    'heading' => 'To urządzenie jest zsynchronizowane',
    'records' => 'Skopiowano :count rekord z urządzenia :peer.|Skopiowano :count rekordy z urządzenia :peer.|Skopiowano :count rekordów z urządzenia :peer.',
    'records_none' => 'Wszystko aktualne — nie było nic nowego do skopiowania. Urządzenie źródłowe: :peer.',
    'withheld' => ':count zmiana jeszcze nie dotarła.|:count zmiany jeszcze nie dotarły.|:count zmian jeszcze nie dotarło.',
    'withheld_action' => 'Podpisało je urządzenie, którego to urządzenie nie może sprawdzić. Nic nie ginie — wszystko zostaje na urządzeniu :peer i dotrze, gdy któreś z Twoich urządzeń przekaże tę tożsamość, a Ty potwierdzisz ją w sekcji :section.',
    'how_it_works' => 'Od tej chwili',
    'automatic_title' => 'To Ty decydujesz, kiedy synchronizować',
    'automatic_body' => 'Cokolwiek zmienisz na jednym urządzeniu, pojawi się na drugim, gdy następnym razem dotkniesz :action. Nie może działać w tle — blokada aplikacji przechowuje jedyny klucz.',
    'lan_title' => 'W tej samej sieci',
    'lan_body' => 'Gdy oba urządzenia są w Twojej sieci domowej, komunikują się bezpośrednio, bez niczego pośrodku.',
    'relay_title' => 'Gdy jesteś poza domem',
    'relay_body' => 'Zmiany czekają zaszyfrowane na Twoim przekaźniku, aż drugie urządzenie wróci do sieci. To urządzenie odbierze je, gdy następnym razem dotkniesz :action.',
    'no_relay_title' => 'Gdy jesteś poza domem',
    'no_relay_body' => 'Zmiany czekają na tym urządzeniu, aż oba znajdą się razem w Twojej sieci domowej i dotkniesz tu :action.',
    'encrypted_title' => 'Tylko Twoje urządzenia mogą to odczytać',
    'encrypted_body' => 'Wszystko jest szyfrowane, zanim opuści urządzenie, a klucze mają wyłącznie Twoje sparowane urządzenia.',
    'continue' => 'Zacznij korzystać z Beatrax',
    'peer_fallback' => 'drugie urządzenie',
];
