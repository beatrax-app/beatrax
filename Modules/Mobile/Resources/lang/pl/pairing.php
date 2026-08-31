<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Sparowane urządzenie',
    'page_title' => 'Sparuj urządzenie',

    'scan_heading' => 'Sparuj to urządzenie',
    'scan_subtitle' => 'Skieruj aparat na kod pokazany na drugim urządzeniu.',
    'camera_permission_pending' => 'Dostęp do aparatu jest wyłączony. Zezwól na niego dla Beatrax w ustawieniach urządzenia i spróbuj ponownie.',
    'open_camera' => 'Otwórz aparat',
    'opening_camera' => 'Oczekiwanie na dostęp do aparatu…',
    'close_camera' => 'Zamknij aparat',
    'viewfinder_aria' => 'Podgląd aparatu — skieruj go na kod na drugim urządzeniu',
    'viewfinder_idle' => 'Aparat jest wyłączony. Otwórz go, aby zeskanować kod pokazany na drugim urządzeniu.',
    'scan_prompt' => 'Zeskanuj kod na drugim urządzeniu',
    'enter_code_instead' => 'Wpisz kod zamiast skanowania',

    'enter_heading' => 'Wpisz kod',
    'camera_off' => 'Dostęp do aparatu jest wyłączony. Zamiast tego wpisz kod z drugiego urządzenia.',
    'camera_off_no_search' => 'Dostęp do aparatu jest wyłączony, a wyszukiwanie drugiego urządzenia w sieci na iPhonie jeszcze nie działa — wpisany kod nie ma więc czym go znaleźć. Włącz z powrotem dostęp do aparatu dla Beatrax w ustawieniach urządzenia i zeskanuj kod z drugiego urządzenia.',
    'no_search' => 'Wyszukiwanie drugiego urządzenia w sieci na iPhonie jeszcze nie działa, więc wpisany kod nie ma czego znaleźć. Zeskanuj kod aparatem — aparat nie musi niczego szukać w sieci.',
    'word_code_aria' => 'Wpisz kod słowny z drugiego urządzenia',
    'submit_code' => 'Wyślij kod',
    'cancel' => 'Anuluj',
    'skip_import' => 'Kontynuuj bez importu',

    'confirm_heading' => 'Porównaj te słowa z drugim urządzeniem',
    'safety_words_aria' => 'Słowa numeru bezpieczeństwa: :words',
    'confirm_body' => 'Oba urządzenia muszą pokazywać dokładnie te same słowa. Jeśli się różnią, dotknij Anuluj — może trwać atak typu man-in-the-middle.',
    'awaiting_peer' => 'Oczekiwanie na potwierdzenie z drugiego urządzenia...',
    'confirm_match' => 'Potwierdź — są zgodne',

    'success_heading' => 'Urządzenie sparowane',
    'success_body' => 'To urządzenie jest teraz zaufane. Twoje dane zsynchronizują się po połączeniu.',
    'done' => 'Gotowe',

    'errors' => [
        'relay_unreachable' => 'Nie można połączyć się z drugim urządzeniem. Upewnij się, że oba są w tej samej sieci, a synchronizacja na komputerze jest włączona.',
        'no_road_home' => 'To urządzenie nie może przeszukać sieci, a zeskanowany kod nie zawiera adresu drugiego urządzenia. Poproś o nowy kod i zeskanuj go.',
        'invalid_code' => 'Ten kod jest nieprawidłowy lub wygasł. Poproś o wygenerowanie nowego na drugim urządzeniu.',
        'code_incomplete' => 'Ten kod nie jest kompletny. Porównaj go z drugim urządzeniem i wpisz go w całości.',
        'code_not_accepted' => 'Żadne urządzenie w tej sieci nie przyjęło tego kodu. Sprawdź kod i czy drugie urządzenie nadal go pokazuje.',
        'no_peer_answered' => 'Nic w tej sieci nie odpowiedziało na ten kod. Sprawdź, czy na drugim urządzeniu działa synchronizacja, albo zeskanuj jego kod aparatem — aparat nie musi niczego szukać w sieci.',
        'no_peer_answered_ios' => 'Nic w tej sieci nie odpowiedziało na ten kod. Wyszukiwanie drugiego urządzenia w sieci na iPhonie jeszcze nie działa, więc zeskanuj jego kod aparatem.',
        'no_peer_answered_camera_off' => 'Nic w tej sieci nie odpowiedziało na ten kod. Wyszukiwanie drugiego urządzenia w sieci na iPhonie jeszcze nie działa, a dostęp do aparatu jest wyłączony — włącz więc z powrotem dostęp do aparatu dla Beatrax w ustawieniach urządzenia i zeskanuj kod z drugiego urządzenia.',
        'rate_limited' => 'Zbyt wiele prób. Poczekaj minutę i spróbuj ponownie.',
        'identity_locked' => 'Tożsamość Twojego urządzenia jest zablokowana. Odblokuj aplikację i spróbuj ponownie.',
        'identity_needs_lock' => 'Najpierw skonfiguruj blokadę aplikacji — to ona chroni tożsamość urządzenia.',
        'safety_number_changed' => 'Drugie urządzenie zmieniło się podczas porównywania. Sprawdź ponownie poniższe słowa przed potwierdzeniem.',
    ],
];
