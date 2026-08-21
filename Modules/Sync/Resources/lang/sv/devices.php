<?php

declare(strict_types=1);

return [
    'heading' => 'Enheter och synkronisering',

    'enable_sync' => 'Aktivera synkronisering',
    'enable_sync_help' => 'Dela dina data säkert mellan betrodda enheter. Kräver ett applås.',

    'app_lock_notice' => 'Ställ in ett applås först för att kunna aktivera synkronisering.',
    'go_to_app_lock' => 'Gå till Applås',

    'encrypted_at_rest' => 'Data krypterade i vila',
    'encrypted_at_rest_scope' => 'Anteckningar, transaktionsbeskrivningar och namn och IBAN för dem du betalar krypteras med din applåslösenfras. Belopp, datum och ditt eget kontonamn och IBAN gör det inte, och vissa handlarnamn står fortfarande i klartext på andra ställen i databasfilen.',
    'on' => 'På',
    'securing' => 'Skyddar dina data…',
    'do_not_close' => 'Stäng inte det här fönstret.',
    'encryption_progress_aria' => 'Förlopp för krypteringen',
    'not_encrypted_offer' => 'Dina data är inte krypterade i vila. Kryptering döljer vem du betalar om enheten tappas bort eller blir stulen — belopp, datum och sökindexet förblir läsbara.',
    'enable_encryption' => 'Aktivera kryptering',

    'your_devices' => 'Dina enheter',

    // Settings keeps a pointer to the moved surface; the section
    // itself now lives on /sync with the status and sync action.
    'moved_help' => 'Parkoppling, enhetsnamn och kryptering finns nu tillsammans med din synkroniseringsstatus.',
    'moved_cta' => 'Öppna Synkronisering och enhet',
    'device_name' => 'Enhetsnamn',
    'save' => 'Spara',
    'peer_default_name' => 'Parkopplad enhet',
    'rename_device' => 'Byt namn på enheten',
    'this_device' => 'Den här enheten',
    'removed' => 'Borttagen',
    'confirmed' => 'Bekräftad',
    'awaiting_confirmation' => 'Väntar på bekräftelse',
    'safety_number_words' => 'Ord för säkerhetsnummer:',
    'paired' => 'Parkopplad',
    'remove_aria' => 'Ta bort :name',
    'remove' => 'Ta bort',
    'pair_new_device' => 'Parkoppla en ny enhet',

    'relay_endpoint' => 'Relay-slutpunkt',
    'relay_endpoint_help' => 'Valfritt. När den är angiven synkroniserar frånkopplade enheter via denna relay. Lämna tomt för endast LAN&#8209;direkt.',
    'relay_endpoint_aria' => 'URL till relay-slutpunkt',
    'relay_insecure_warning' => 'Den här relay-slutpunkten använder vanlig HTTP. Relayen dekrypterar aldrig dina data, men en osäker anslutning avslöjar krypterade storlekar och tidpunkter för den som avlyssnar nätverket. Använd en <strong>https://</strong>-slutpunkt för bästa integritet.',

    'enable_at_rest' => 'Aktivera kryptering i vila',
    'enable_at_rest_body' => 'Dina data krypteras med lösenfrasen för ditt applås. En säkerhetskopia skapas automatiskt före migreringen.',
    'no_recovery_warning' => 'Om du tappar bort lösenfrasen för ditt applås och saknar säkerhetskopia och andra betrodda enheter går dina data inte att återställa.',
    'recover_help' => 'För att få tillbaka åtkomsten kan du parkoppla den här enheten på nytt från en annan betrodd enhet, eller använda din fristående krypterade säkerhetskopia.',
    'amounts_plaintext' => 'Belopp krypteras inte i vila — saldon och summor förblir läsbara så att dina månadssummor fortsätter att stämma.',
    'search_plaintext' => 'Sökindexet behåller en kopia i klartext av handlar- och beskrivningstext så att fritextsökning fortsätter att fungera.',
    'keep_unencrypted' => 'Behåll data okrypterade',
    'encryption_enabled' => 'Kryptering aktiverad',
    'encryption_enabled_body' => 'Dina data är nu krypterade i vila.',
    'done_encryption_enabled' => 'Klar — kryptering aktiverad',
    'encryption_failed' => 'Krypteringen kunde inte ställas in',
    'encryption_failed_body' => 'Dina data ändrades inte. Din säkerhetskopia behölls.',
    'close_no_changes' => 'Stäng — inga ändringar gjordes',

    'remove_this_device' => 'Ta bort den här enheten',
    'removing' => 'Tar bort:',
    'remove_rotates_key' => 'När du tar bort den här enheten roteras krypteringsnyckeln så att den inte får några framtida uppdateringar.',
    'remove_cannot_erase' => 'Det kan inte radera data som redan finns på enheten. Om enheten har tappats bort eller blivit stulen bör du betrakta alla data den innehöll som röjda.',
    'remove_device' => 'Ta bort enheten',
    'keep_device' => 'Behåll enheten',
    'rotating_key' => 'Roterar krypteringsnyckeln…',

    'flash' => [
        'app_lock_first' => 'Ställ in ett applås först för att kunna aktivera synkronisering.',
        'enable_failed' => 'Det gick inte att aktivera synkronisering. Kontrollera att ditt applås är aktivt och försök igen.',
        'cannot_remove_self' => 'Du kan inte ta bort den här enheten — det är den du använder.',
        'remove_failed' => 'Det gick inte att ta bort enheten. Försök igen.',
        'app_lock_first_settings' => 'Ställ in ett applås först för att kunna ändra synkroniseringsinställningarna.',
        'relay_cleared' => 'Relay-slutpunkten rensades.',
        'relay_saved' => 'Relay-slutpunkten sparades.',
        'relay_save_failed' => 'Det gick inte att spara relay-slutpunkten: :message',
    ],
];
