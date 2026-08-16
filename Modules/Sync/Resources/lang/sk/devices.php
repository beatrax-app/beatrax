<?php

declare(strict_types=1);

return [
    'heading' => 'Zariadenia a synchronizácia',

    'enable_sync' => 'Zapnúť synchronizáciu',
    'enable_sync_help' => 'Zdieľaj svoje údaje bezpečne medzi dôveryhodnými zariadeniami. Vyžaduje zámok aplikácie.',

    'app_lock_notice' => 'Ak chceš zapnúť synchronizáciu, najprv nastav zámok aplikácie.',
    'go_to_app_lock' => 'Prejsť na Zámok aplikácie',

    'encrypted_at_rest' => 'Údaje šifrované v pokoji',
    'encrypted_at_rest_help' => 'Tvoje údaje chráni prístupová fráza zámku aplikácie.',
    'on' => 'Zap.',
    'securing' => 'Zabezpečujú sa tvoje údaje…',
    'do_not_close' => 'Nezatváraj toto okno.',
    'not_encrypted_offer' => 'Tvoje údaje nie sú šifrované v pokoji. Nastav šifrovanie, aby boli chránené, ak sa toto zariadenie stratí alebo ho niekto ukradne.',
    'enable_encryption' => 'Zapnúť šifrovanie',

    'your_devices' => 'Tvoje zariadenia',

    'moved_help' => 'Párovanie, názvy zariadení a šifrovanie nájdeš teraz pri stave synchronizácie.',
    'moved_cta' => 'Otvoriť Synchronizáciu a zariadenie',
    'device_name' => 'Názov zariadenia',
    'save' => 'Uložiť',
    'peer_default_name' => 'Spárované zariadenie',
    'rename_device' => 'Premenovať zariadenie',
    'this_device' => 'Toto zariadenie',
    'removed' => 'Odstránené',
    'confirmed' => 'Potvrdené',
    'awaiting_confirmation' => 'Čaká na potvrdenie',
    'safety_number_words' => 'Slová bezpečnostného čísla:',
    'paired' => 'Spárované',
    'remove_aria' => 'Odstrániť: :name',
    'remove' => 'Odstrániť',
    'pair_new_device' => 'Spárovať nové zariadenie',

    'relay_endpoint' => 'Adresa relé',
    'relay_endpoint_help' => 'Voliteľné. Keď je nastavená, zariadenia offline sa synchronizujú cez toto relé. Nechaj prázdne, ak chceš iba priame LAN&#8209;spojenie.',
    'relay_endpoint_aria' => 'URL adresa relé',
    'relay_insecure_warning' => 'Táto adresa relé používa obyčajné HTTP. Relé tvoje údaje nikdy nedešifruje, no nezabezpečené spojenie prezradí pozorovateľom siete veľkosti a časovanie zašifrovaných prenosov. Pre najlepšie súkromie použi adresu <strong>https://</strong>.',

    'enable_at_rest' => 'Zapnúť šifrovanie v pokoji',
    'enable_at_rest_body' => 'Tvoje údaje sa zašifrujú prístupovou frázou zámku aplikácie. Záloha pred migráciou vznikne automaticky.',
    'no_recovery_warning' => 'Ak stratíš prístupovú frázu zámku aplikácie a nemáš zálohu ani iné dôveryhodné zariadenie, údaje sa nedajú obnoviť.',
    'recover_help' => 'Prístup obnovíš tak, že toto zariadenie znova spáruješ z iného dôveryhodného zariadenia, alebo použiješ svoju samostatnú šifrovanú zálohu.',
    'amounts_plaintext' => 'Sumy nie sú šifrované v pokoji — zostatky a súčty ostávajú čitateľné, takže mesačné súčty naďalej sedia.',
    'search_plaintext' => 'Vyhľadávací index si drží nešifrovanú kópiu textu obchodníkov a popisov, aby fulltextové vyhľadávanie fungovalo ďalej.',
    'keep_unencrypted' => 'Nechať údaje nešifrované',
    'encryption_enabled' => 'Šifrovanie zapnuté',
    'encryption_enabled_body' => 'Tvoje údaje sú teraz šifrované v pokoji.',
    'done_encryption_enabled' => 'Hotovo — šifrovanie zapnuté',
    'encryption_failed' => 'Nastavenie šifrovania zlyhalo',
    'encryption_failed_body' => 'Tvoje údaje sa nezmenili. Záloha zostala zachovaná.',
    'close_no_changes' => 'Zavrieť — bez zmien',

    'remove_this_device' => 'Odstrániť toto zariadenie',
    'removing' => 'Odstraňuje sa:',
    'remove_rotates_key' => 'Odstránenie tohto zariadenia vymení šifrovací kľúč, takže zariadenie už nedostane žiadne ďalšie aktualizácie.',
    'remove_cannot_erase' => 'Údaje, ktoré na tom zariadení už sú, sa vymazať nedajú. Ak sa zariadenie stratilo alebo ho niekto ukradol, ber všetky údaje na ňom ako vyzradené.',
    'remove_device' => 'Odstrániť zariadenie',
    'keep_device' => 'Ponechať zariadenie',
    'rotating_key' => 'Vymieňa sa šifrovací kľúč…',

    'flash' => [
        'app_lock_first' => 'Ak chceš zapnúť synchronizáciu, najprv nastav zámok aplikácie.',
        'enable_failed' => 'Synchronizáciu sa nepodarilo zapnúť. Skontroluj, či je zámok aplikácie aktívny, a skús to znova.',
        'cannot_remove_self' => 'Toto zariadenie odstrániť nemôžeš — práve ho používaš.',
        'remove_failed' => 'Zariadenie sa nepodarilo odstrániť. Skús to znova.',
        'app_lock_first_settings' => 'Ak chceš zmeniť nastavenia synchronizácie, najprv nastav zámok aplikácie.',
        'relay_cleared' => 'Adresa relé vymazaná.',
        'relay_saved' => 'Adresa relé uložená.',
        'relay_save_failed' => 'Adresu relé sa nepodarilo uložiť: :message',
    ],
];
