<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => "Tento telefon neumí uložit soubor, který mu aplikace předá, proto se šifrovaná záloha vytváří v desktopové aplikaci. Spárujte toto zařízení, aby zůstala synchronizovaná.",
        'unavailable' => 'Šifrované zálohy jsou dostupné v desktopové verzi (SQLite). U databáze na serveru použij zálohovací nástroje samotné databáze.',
        'intro' => 'Stáhni si kopii celé své databáze zašifrovanou přístupovou frází — bezpečně ji uložíš na externí disk nebo do cloudu, protože bez fráze je nečitelná (kvantově odolné XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Přístupová fráze',
        'confirm_passphrase' => 'Potvrzení přístupové fráze',
        'keep_safe' => 'Ulož přístupovou frázi na bezpečné místo — bez ní zálohu nijak neobnovíš.',
        'submit' => 'Stáhnout šifrovanou zálohu',
        'preparing' => 'Připravuje se…',
    ],

    'restore' => [
        'heading' => 'Obnovení ze zálohy',

        'intro_html' => 'Nahraď svou současnou databázi šifrovanou zálohou. Soubor se před jakoukoli změnou dešifruje a zkontroluje a nejdřív se uloží snímek současných dat — přesto to <strong class="text-slate-700 dark:text-slate-200">přepíše všechno</strong>, proto je krok zajištěný.',
        'restored' => 'Obnoveno. Načti aplikaci znovu a uvidíš obnovená data.',
        'snapshot_saved_prefix' => 'Snímek tvých předchozích dat byl uložen do',
        'file_label' => 'Šifrovaná záloha (.enc)',
        'uploading' => 'Nahrává se…',
        'passphrase' => 'Přístupová fráze',
        'confirm_prefix' => 'Napiš',
        'confirm_suffix' => 'pro potvrzení',
        'submit' => 'Obnovit (přepíše současná data)',
        'restoring' => 'Obnovuje se…',
    ],

    'errors' => [
        'passphrase_min' => 'Použij přístupovou frázi o délce alespoň :min znak.|Použij přístupovou frázi o délce alespoň :min znaky.|Použij přístupovou frázi o délce alespoň :min znaků.',
        'passphrase_mismatch' => 'Obě přístupové fráze se neshodují.',
        'download_sqlite_only' => 'Šifrované stažení je dostupné jen ve verzi se SQLite.',
        'create_failed' => 'Zálohu se nepodařilo vytvořit: :message',
        'confirm_phrase' => 'Napiš :phrase pro potvrzení — tímto nahradíš svá současná data.',
        'choose_file' => 'Vyber soubor se šifrovanou zálohou (.enc), který chceš obnovit.',
        'enter_passphrase' => 'Zadej přístupovou frázi, kterou byla záloha zašifrovaná.',
        'unreadable' => 'Nahraný soubor se nepodařilo přečíst. Zkus to znovu.',
    ],
];
