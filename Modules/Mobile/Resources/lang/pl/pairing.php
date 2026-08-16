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
    'word_code_aria' => 'Wpisz kod słowny z drugiego urządzenia',
    'submit_code' => 'Wyślij kod',
    'cancel' => 'Anuluj',

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
        'import_needs_qr' => 'Zeskanuj kod QR pokazany na drugim urządzeniu, aby zaimportować dane.',
        'invalid_code' => 'Ten kod jest nieprawidłowy lub wygasł. Poproś o wygenerowanie nowego na drugim urządzeniu.',
        'identity_locked' => 'Tożsamość Twojego urządzenia jest zablokowana. Odblokuj aplikację i spróbuj ponownie.',
    ],
];
