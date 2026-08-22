<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Biometrisk upplåsning är inte tillgänglig på den här enheten.',
    'error_enroll_unprotected' => 'Biometrisk upplåsning behöver ett nyckelförvar i operativsystemet, och den här installationen har inget. Registrering skulle lämna upplåsningsnyckeln läsbar bredvid dina data, så den erbjuds inte här.',
    'error_enroll_locked' => 'Lås upp appen innan du registrerar enheten.',
    'error_enroll_failed' => 'Din enhet nekade att lagra nyckeln. Biometrisk upplåsning är inte tillgänglig.',
    'heading' => 'Applås',

    'moved_help' => 'Din PIN-kod, tid för automatiskt lås och biometrisk upplåsning finns bland enhetens synkroniseringsinställningar.',
    'moved_cta' => 'Öppna Synkronisering och enhet',

    'toggle_label' => 'Lås appen med PIN-kod',
    'toggle_description' => 'Ersätter daglig inloggning med en PIN-kod. Sessioner är aktiva i 30 dagar.',

    'setup_heading' => 'Ange en PIN-kod för att aktivera låset',
    'new_pin_label' => 'Ny PIN-kod (6–10 siffror)',
    'confirm_pin_label' => 'Bekräfta PIN-kod',
    'account_password_label' => 'Kontolösenord',
    'account_password_note' => '(krävs för att skapa en återställningsnyckel)',
    'account_password_placeholder' => 'Ditt kontolösenord',
    'set_pin' => 'Ange PIN-kod',

    'pin_row_label' => 'PIN-kod',
    'pin_row_description' => 'Ändra din nuvarande PIN-kod.',
    'change_pin' => 'Ändra PIN-kod',
    'forgot_pin_link' => 'Glömt din PIN-kod? Återställ den med ditt kontolösenord.',

    'biometric_enrolled_description' => 'Den här enheten är registrerad för biometrisk upplåsning.',
    'biometric_enroll_description' => 'Registrera den här enheten för att låsa upp med biometri.',
    'remove' => 'Ta bort',
    'enroll' => 'Registrera',
    'biometric_unavailable' => 'Biometrisk upplåsning är inte tillgänglig på den här enheten.',

    'deenroll_modal_heading' => 'Ta bort biometrisk upplåsning — bekräfta med PIN-kod',
    'current_pin_label' => 'Nuvarande PIN-kod',
    'remove_biometric' => 'Ta bort biometri',
    'keep_biometric' => 'Behåll biometri',

    'auto_lock' => 'Lås automatiskt efter',
    'idle_1' => '1 minut',
    'idle_5' => '5 minuter',
    'idle_15' => '15 minuter',
    'idle_30' => '30 minuter',

    'disable_modal_heading' => 'Inaktivera applås — bekräfta med PIN-kod',
    'disable_lock' => 'Inaktivera lås',
    'keep_lock' => 'Behåll applås',

    'forgot_modal_heading' => 'Återställ PIN-kod — bekräfta med kontolösenord',
    'forgot_modal_body' => 'Ditt kontolösenord återskapar låsnyckeln, så du förlorar aldrig data när du återställer PIN-koden.',
    'confirm_new_pin_label' => 'Bekräfta ny PIN-kod',
    'reset_pin' => 'Återställ PIN-kod',
    'cancel' => 'Avbryt',

    'change_modal_heading' => 'Ändra PIN-kod — bekräfta med nuvarande PIN-kod',
    'keep_pin' => 'Behåll PIN-kod',

    'error_pin_too_short' => 'PIN-koden måste ha minst 6 siffror.',
    'error_pin_mismatch' => 'PIN-koderna stämmer inte överens. Försök igen.',
    'error_pin_required' => 'Ange din PIN-kod.',
    'error_pin_incorrect' => 'Fel PIN-kod.',
    'error_account_password_required' => 'Ange ditt kontolösenord.',
    'error_account_password' => 'Fel kontolösenord.',
    'change_pin_success' => 'Din krypteringsnyckel har säkrats om med din nya PIN-kod.',
    'error_forgot_failed' => 'Återställningen av PIN-koden misslyckades — återställningsnyckeln är inte tillgänglig.',
    'error_enable_first' => 'Aktivera PIN-låset innan du registrerar biometri.',
    'error_disable_blocked_by_encryption' => 'Dina anteckningar och motpartsuppgifter är krypterade med nyckeln som det här applåset håller, så att stänga av låset skulle göra dem oläsliga. Låset förblir på — byt PIN-kod i stället.',
    'error_key_material_lost' => 'Den här enheten håller inte längre nyckeln som öppnar dina krypterade data, så en ny PIN-kod gör dem inte läsbara igen. Parkoppla den här enheten med en som fortfarande har nyckeln för att få tillbaka dem.',
    'error_recovery_wrap_stale' => 'Ditt kontolösenord öppnar inte längre det här applåset — det byttes efter att låset sattes upp. Din PIN-kod fungerar fortfarande, men det finns ingenting bakom den om du glömmer den. Koppla om ditt kontolösenord nu.',
    'relink_recovery' => 'Koppla om kontolösenordet',
    'relink_modal_heading' => 'Koppla om kontolösenordet — bekräfta med PIN-kod',
    'relink_recovery_success' => 'Ditt kontolösenord kan återställa det här applåset igen.',
];
