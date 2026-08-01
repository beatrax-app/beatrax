<?php

declare(strict_types=1);

return [
    'heading' => 'Apparaten & synchronisatie',

    'enable_sync' => 'Synchronisatie inschakelen',
    'enable_sync_help' => 'Deel je gegevens veilig tussen vertrouwde apparaten. Vereist een app-vergrendeling.',

    'app_lock_notice' => 'Stel eerst een app-vergrendeling in om synchronisatie in te schakelen.',
    'go_to_app_lock' => 'Ga naar App-vergrendeling',

    'encrypted_at_rest' => 'Gegevens versleuteld opgeslagen',
    'encrypted_at_rest_help' => 'Je gegevens zijn beveiligd met je app-vergrendelingswachtwoord.',
    'on' => 'Aan',
    'securing' => 'Je gegevens worden beveiligd…',
    'do_not_close' => 'Sluit dit venster niet.',
    'not_encrypted_offer' => 'Je gegevens zijn niet versleuteld opgeslagen. Stel versleuteling in om ze te beschermen als dit apparaat verloren of gestolen raakt.',
    'enable_encryption' => 'Versleuteling inschakelen',

    'your_devices' => 'Je apparaten',
    'device_name' => 'Apparaatnaam',
    'save' => 'Opslaan',
    'rename_device' => 'Apparaat hernoemen',
    'this_device' => 'Dit apparaat',
    'removed' => 'Verwijderd',
    'confirmed' => 'Bevestigd',
    'awaiting_confirmation' => 'Wacht op bevestiging',
    'safety_number_words' => 'Veiligheidsnummerwoorden:',
    'paired' => 'Gekoppeld',
    'remove_aria' => ':name verwijderen',
    'remove' => 'Verwijderen',
    'pair_new_device' => 'Een nieuw apparaat koppelen',

    'relay_endpoint' => 'Relay-endpoint',
    'relay_endpoint_help' => 'Optioneel. Als dit is ingesteld, synchroniseren offline apparaten via deze relay. Laat leeg voor alleen LAN&#8209;direct.',
    'relay_endpoint_aria' => 'Relay-endpoint-URL',
    'relay_insecure_warning' => 'Dit relay-endpoint gebruikt gewoon HTTP. De relay ontsleutelt je gegevens weliswaar nooit, maar een onbeveiligde verbinding stelt versleutelde groottes en timing bloot aan waarnemers op het netwerk. Gebruik een <strong>https://</strong>-endpoint voor de beste privacy.',

    'enable_at_rest' => 'Versleuteling in rust inschakelen',
    'enable_at_rest_body' => 'Je gegevens worden versleuteld met je app-vergrendelingswachtwoord. Er wordt automatisch een back-up gemaakt voordat de migratie begint.',
    'no_recovery_warning' => 'Als je je app-vergrendelingswachtwoord verliest en geen back-up of ander vertrouwd apparaat hebt, kunnen je gegevens niet worden hersteld.',
    'recover_help' => 'Om weer toegang te krijgen, koppel je dit apparaat opnieuw vanaf een ander vertrouwd apparaat, of gebruik je je eigen versleutelde back-up.',
    'amounts_plaintext' => 'Bedragen worden niet versleuteld opgeslagen — saldi en totalen blijven leesbaar zodat je maandtotalen correct blijven optellen.',
    'search_plaintext' => 'De zoekindex bewaart een leesbare kopie van winkelier- en omschrijvingstekst zodat zoeken op volledige tekst blijft werken.',
    'keep_unencrypted' => 'Gegevens onversleuteld houden',
    'encryption_enabled' => 'Versleuteling ingeschakeld',
    'encryption_enabled_body' => 'Je gegevens zijn nu versleuteld opgeslagen.',
    'done_encryption_enabled' => 'Klaar — versleuteling ingeschakeld',
    'encryption_failed' => 'Instellen van versleuteling mislukt',
    'encryption_failed_body' => 'Je gegevens zijn niet gewijzigd. Je back-up is behouden.',
    'close_no_changes' => 'Sluiten — geen wijzigingen aangebracht',

    'remove_this_device' => 'Dit apparaat verwijderen',
    'removing' => 'Verwijderen:',
    'remove_rotates_key' => 'Dit apparaat verwijderen roteert de versleutelingssleutel zodat het geen toekomstige updates meer ontvangt.',
    'remove_cannot_erase' => 'Het kan gegevens die al op dat apparaat staan niet wissen. Als dit apparaat verloren of gestolen is, beschouw alle gegevens die het bevatte dan als blootgesteld.',
    'remove_device' => 'Apparaat verwijderen',
    'keep_device' => 'Apparaat behouden',
    'rotating_key' => 'Versleutelingssleutel roteren…',

    'flash' => [
        'app_lock_first' => 'Stel eerst een app-vergrendeling in om synchronisatie in te schakelen.',
        'enable_failed' => 'Synchronisatie inschakelen mislukt. Zorg dat je app-vergrendeling actief is en probeer het opnieuw.',
        'cannot_remove_self' => 'Je kunt dit apparaat niet verwijderen — het is het apparaat dat je nu gebruikt.',
        'remove_failed' => 'Apparaat verwijderen mislukt. Probeer het opnieuw.',
        'app_lock_first_settings' => 'Stel eerst een app-vergrendeling in om synchronisatie-instellingen te wijzigen.',
        'relay_cleared' => 'Relay-endpoint gewist.',
        'relay_saved' => 'Relay-endpoint opgeslagen.',
        'relay_save_failed' => 'Relay-endpoint opslaan mislukt: :message',
    ],
];
