<?php

declare(strict_types=1);

return [
    'heading' => 'Zařízení a synchronizace',

    'enable_sync' => 'Zapnout synchronizaci',
    'enable_sync_help' => 'Sdílej svá data bezpečně mezi důvěryhodnými zařízeními. Vyžaduje zámek aplikace. Jakmile je zapnutý, data se zašifrují a zámek už nelze vypnout.',

    'app_lock_notice' => 'Nejdřív nastav zámek aplikace, pak půjde synchronizaci zapnout.',
    'go_to_app_lock' => 'Přejít na Zámek aplikace',

    'identity_unreadable' => 'Synchronizační identita tohoto zařízení vznikla s jiným zámkem aplikace a už se neotevře. Dokud to platí, zařízení nemůže synchronizovat ani se párovat. Obnovením zálohy databáze, se kterou vznikla, bude znovu čitelná.',
    'identity_unreadable_replace_help' => 'Můžeš také začít znovu: zařízení dostane novou identitu, stará zůstane nepoužitá stranou a dříve spárovaná zařízení bude potřeba spárovat znovu.',
    'identity_unreadable_replace' => 'Vytvořit pro toto zařízení novou identitu',

    'encrypted_at_rest' => 'Data šifrovaná v úložišti',
    'encrypted_at_rest_scope' => 'Poznámky, popisy transakcí a jména a IBAN příjemců jsou v účetní knize šifrovány přístupovou frází zámku aplikace. Částky, data a název a IBAN tvého vlastního účtu nikoli. Index vyhledávání si drží vlastní čitelnou kopii toho, komu platíš, popisů tvých transakcí a tvých daňových poznámek, a některá jména obchodníků zůstávají čitelná jinde v souboru databáze.',
    'on' => 'Zapnuto',
    'securing' => 'Zabezpečují se tvá data…',
    'do_not_close' => 'Nezavírej toto okno.',
    'encryption_progress_aria' => 'Průběh šifrování',
    'not_encrypted_offer' => 'Vaše data nejsou šifrována v klidu. Šifrování skryje, komu platíte, pokud toto zařízení ztratíte nebo vám ho ukradnou — částky, data a index vyhledávání zůstávají čitelné.',
    'enable_encryption' => 'Zapnout šifrování',

    'your_devices' => 'Tvá zařízení',

    'moved_help' => 'Párování, názvy zařízení a šifrování teď najdeš u stavu synchronizace.',
    'moved_cta' => 'Otevřít Synchronizaci a zařízení',
    'device_name' => 'Název zařízení',
    'save' => 'Uložit',
    'peer_default_name' => 'Spárované zařízení',
    'rename_device' => 'Přejmenovat zařízení',
    'this_device' => 'Toto zařízení',
    'removed' => 'Odebráno',
    'confirmed' => 'Potvrzeno',
    'awaiting_confirmation' => 'Čeká na potvrzení',
    'safety_number_words' => 'Slova bezpečnostního čísla:',
    'paired' => 'Spárováno',
    'remove_aria' => 'Odebrat: :name',
    'remove' => 'Odebrat',
    'pair_new_device' => 'Spárovat nové zařízení',

    'pairing_waiting' => 'Dokončete párování se zařízením :name',
    'pairing_waiting_help' => 'Oba displeje musí zobrazovat stejných šest slov, než párování začne platit. Otevřete je znovu a porovnejte je.',
    'pairing_waiting_resume' => 'Pokračovat v párování',
    'pairing_waiting_lock_override' => 'Odemknutí toto párování znovu otevře, místo aby ho nechalo vypršet, takže přetrvá déle než nastavený časový limit zámku aplikace. Skončí, jakmile ho dokončíte nebo zrušíte.',

    'relay_endpoint' => 'Adresa relay serveru',
    'relay_endpoint_help' => 'Volitelné. Když je nastavená, zařízení offline se synchronizují přes tento relay server. Nech prázdné, pokud chceš jen spojení LAN&#8209;přímo.',
    'relay_endpoint_aria' => 'URL relay serveru',
    'relay_insecure_warning' => 'Tato adresa relay serveru používá prosté HTTP. Relay server tvá data nikdy nedešifruje, ale nezabezpečené spojení odhalí pozorovatelům v síti velikosti a časování zašifrovaných přenosů. Pro nejlepší soukromí použij adresu <strong>https://</strong>.',

    'enable_at_rest' => 'Zapnout šifrování v úložišti',
    'enable_at_rest_body' => 'Tvá data se zašifrují heslem zámku aplikace. Záloha před migrací vznikne automaticky.',
    'no_recovery_warning' => 'Pokud ztratíš heslo zámku aplikace a nemáš zálohu ani jiné důvěryhodné zařízení, data se obnovit nedají.',
    'recover_help' => 'Přístup obnovíš tak, že toto zařízení znovu spáruješ z jiného důvěryhodného zařízení, nebo použiješ svou vlastní šifrovanou zálohu.',
    'amounts_plaintext' => 'Částky se v úložišti nešifrují — zůstatky a součty zůstávají čitelné, aby ti měsíční součty dál správně vycházely.',
    'search_plaintext' => 'Index vyhledávání si drží nešifrovanou kopii názvů obchodníků a popisů, aby fulltextové vyhledávání dál fungovalo.',
    'keep_unencrypted' => 'Nechat data nešifrovaná',
    'encryption_enabled' => 'Šifrování zapnuto',
    'encryption_enabled_scope' => 'Poznámky, popisy a to, komu platíš, jsou teď šifrované přístupovou frází zámku aplikace. Částky, data a index vyhledávání zůstávají čitelné.',
    'done_encryption_enabled' => 'Hotovo — šifrování zapnuto',
    'encryption_failed' => 'Nastavení šifrování selhalo',
    'encryption_failed_body' => 'Tvá data se nezměnila. Záloha zůstala zachovaná.',
    'close_no_changes' => 'Zavřít — nic se nezměnilo',

    'remove_this_device' => 'Odebrat toto zařízení',
    'removing' => 'Odebírá se:',
    'remove_rotates_key' => 'Odebrání tohoto zařízení vymění šifrovací klíč, takže zařízení už nedostane žádné další aktualizace.',
    'remove_cannot_erase' => 'Data, která na něm už jsou, to smazat nedokáže. Pokud se zařízení ztratilo nebo ho někdo ukradl, ber všechna data na něm jako vyzrazená.',
    'remove_device' => 'Odebrat zařízení',
    'keep_device' => 'Ponechat zařízení',
    'rotating_key' => 'Vyměňuje se šifrovací klíč…',

    'flash' => [
        'app_lock_first' => 'Nejdřív nastav zámek aplikace, pak půjde synchronizaci zapnout.',
        'enable_failed' => 'Synchronizaci se nepodařilo zapnout. Zkontroluj, že je zámek aplikace aktivní, a zkus to znovu.',
        'identity_replaced' => 'Toto zařízení má novou synchronizační identitu. Spáruj ostatní zařízení znovu.',
        'identity_replace_failed' => 'Starou identitu zařízení se nepodařilo odložit stranou. Zkus to znovu.',
        'cannot_remove_self' => 'Toto zařízení odebrat nemůžeš — právě ho používáš.',
        'remove_failed' => 'Zařízení se nepodařilo odebrat. Zkus to prosím znovu.',
        'app_lock_first_settings' => 'Nejdřív nastav zámek aplikace, pak půjde nastavení synchronizace změnit.',
        'relay_cleared' => 'Adresa relay serveru vymazána.',
        'relay_saved' => 'Adresa relay serveru uložena.',
        'relay_save_failed' => 'Adresu relay serveru se nepodařilo uložit: :message',
    ],
    'app_lock_permanent' => 'Jakmile jsou data šifrovaná, zámek aplikace už nelze vypnout — drží jediný klíč a cesta zpět k nešifrovaným datům neexistuje.',
    'backlog_heading' => 'Čeká na přidání',
    'backlog_deferred' => 'Toto zařízení přijalo data z jiného zařízení a zatím je nepřidalo do tvé evidence. Nic se neztrácí — přidají se automaticky, obvykle během okamžiku.',
    'backlog_awaiting_key' => 'Toto zařízení přijalo data, ke kterým zatím nemá klíč. Nic se neztrácí. Otevři aplikaci na spárovaném zařízení, zatímco je toto otevřené, aby se obě mohla spojit a klíč se mohl odeslat.',
];
