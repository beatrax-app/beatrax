<?php

declare(strict_types=1);

return [
    'page_title' => 'Importera från en annan enhet',

    'heading' => 'Importera från en annan enhet',
    'subtitle' => 'Konfigurera den här telefonen med ett eget konto och lås, och parkoppla den sedan med din andra enhet för att hämta in din historik.',

    'username' => 'Användarnamn',
    'password' => 'Lösenord',
    'password_help' => 'Minst 12 tecken — det finns ingen återställning av lösenord, bara återställningskoder.',
    'confirm_password' => 'Bekräfta lösenord',
    'pin' => 'PIN-kod för applås',
    'pin_help' => '6-10 siffror — låser upp den här enheten.',
    'confirm_pin' => 'Bekräfta PIN-kod',
    'continue' => 'Fortsätt',

    'failed_heading' => 'Konfigurationen blev inte klar',
    'failed_body' => 'Ditt konto skapades, men vi kunde inte slutföra konfigurationen av den här enheten. Du kan tryggt försöka igen.',
    'try_again' => 'Försök igen',

    'recovery_heading' => 'Spara de här återställningskoderna',
    'recovery_body' => 'Skriv ut dem eller spara dem på ett säkert ställe. De visas inte igen.',
    'already_heading' => 'Den här enheten är redan konfigurerad',
    'already_body' => 'Ditt konto finns redan på den här enheten. Gå vidare till parkopplingen för att ansluta den till dina andra enheter.',
    'recovery_download' => 'Ladda ner som .txt',
    'recovery_copy' => 'Kopiera koder',
    'recovery_copied' => 'Kopierat',
    'recovery_copy_failed' => 'Det gick inte att kopiera. Skriv ner koderna i stället.',
    'recovery_saved' => 'Sparad i dina nedladdningar.',
    'recovery_share_title' => 'Beatrax-återställningskoder',
    'recovery_share_message' => 'Förvara dem på ett säkert ställe.',
    'recovery_save_failed' => 'Det gick inte att spara filen. Skriv ner koderna i stället.',
    'recovery_confirm' => 'Jag har sparat de här koderna på ett säkert ställe.',
    'continue_to_pairing' => 'Fortsätt till parkopplingen',

    'errors' => [
        'passwords_mismatch' => 'Lösenorden stämmer inte överens.',
        'password_length' => 'Använd minst 12 tecken.',
        'pin_length' => 'PIN-koden måste ha minst 6 siffror.',
        'pins_mismatch' => 'PIN-koderna stämmer inte överens. Försök igen.',
        'session_expired' => 'Din session gick ut innan konfigurationen blev klar. Ange din PIN-kod och ditt lösenord igen.',
        'retry_failed' => 'Det gick fortfarande inte att slutföra konfigurationen av den här enheten. Försök igen.',
        'account_failed' => 'Det gick inte att skapa kontot.',
    ],
];
