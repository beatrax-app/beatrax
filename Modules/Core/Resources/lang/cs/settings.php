<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Zobrazení',
        'money' => 'Peníze',
        'insights' => 'Přehledy a upozornění',
        'security' => 'Zabezpečení a zařízení',
        'data' => 'Importy a data',
        'app' => 'Aplikace',
    ],

    'title' => 'Nastavení',
    'subtitle' => 'Předvolby toho, jak se tvé finance v aplikaci zobrazují.',

    'appearance' => [
        'heading' => 'Vzhled',
        'theme' => 'Motiv',
        'theme_light' => 'Světlý',
        'theme_dark' => 'Tmavý',
        'theme_system' => 'Systémový',
        'theme_help' => 'Systémový se řídí světlým nebo tmavým nastavením tvého operačního systému.',
    ],

    'language' => [
        'apply' => 'Použít',
        'heading' => 'Jazyk',
        'label' => 'Jazyk rozhraní',

        'system' => 'Systémový',
        'help' => 'Systémový se řídí jazykem prohlížeče nebo operačního systému, výchozí je angličtina.',
    ],

    'currency_display' => [
        'heading' => 'Zobrazení měny',
        'label' => 'Výchozí zobrazení v seznamu transakcí',
        'eur_only' => 'Jen EUR',
        'original' => 'Původní měna',
        'help' => 'Přímo v seznamu transakcí to můžeš kdykoli přepnout pro danou stránku.',
    ],

    'base_currency' => [
        'heading' => 'Základní měna výkazů',
        'label' => 'Měna výkazů',
        'help' => 'Všechny součty a souhrny se převádějí na tuto měnu. U každého účtu se vedle toho pořád zobrazuje jeho původní měna.',
    ],

    'exchange_rates' => [
        'heading' => 'Směnné kurzy',
        'fetch_online' => 'Stahovat aktuální kurzy online',
        'online_on' => 'Kurzy se denně stahují z ECB. Jen dotazy na měnové páry — žádná osobní data.',
        'last_updated' => 'Naposledy aktualizováno: :date.',
        'online_off' => 'Používají se přibalené kurzy. Ze zařízení neodchází žádná data.',
        'fetch_aria' => 'Stáhnout aktuální směnné kurzy online',
        'refreshing' => 'Aktualizace…',
        'next_refresh' => 'Příští automatická aktualizace: denně v 09:00',
        'refresh_gave_up' => 'Kurzy se nepodařilo aktualizovat. Nadále se používají kurzy uložené v tomto zařízení.',
        'refresh_now' => 'Aktualizovat',
    ],

    'period' => [
        'heading' => 'Období',
        'label' => 'Období začíná dnem',
        'help' => 'Číslo 1 až 28. Většina lidí to nechává na 1 (kalendářní měsíc). Zvol 25, pokud ti mzda chodí 25. a svůj měsíc počítáš od té doby.',
    ],

    'recurring' => [
        'heading' => 'Rozpoznávání opakovaných plateb',
        'window_label' => 'Okno detekce (měsíce)',
        'window_help' => 'Kolik měsíců historie prohledávat při shlukování transakcí do opakovaných vzorců.',
        'income_label' => 'Minimální příjem (centy)',
        'income_help' => 'Příjmy pod touto hranicí se automaticky neshlukují. Ukládá se v centech — 200000 znamená 2000,00 €. Nastav 0, ať se hranice nepoužije.',
    ],

    'drift' => [
        'heading' => 'Upozornění na odchylku',
        'label' => 'Výchozí práh upozornění na odchylku',
        'help' => 'Upozornění se spustí, když se poslední částka opakované platby liší od předchozí o víc než o toto procento. Nastavení u jednotlivých řad má přednost.',
        'options' => [
            '1' => '±1%',
            '2' => '±2%',
            '5' => '±5% (výchozí)',
            '10' => '±10%',
            '25' => '±25%',
            '50' => '±50%',
        ],
    ],

    'save' => 'Uložit nastavení',
    'saved' => 'Uloženo.',

    'anomaly_heading' => 'Detekce anomálií',
    'notifications_heading' => 'Oznámení',

    'forecasting' => [
        'heading' => 'Předpověď',
        'intro' => 'Beatrax promítá tvůj zůstatek dopředu z aktuálního stavu tvých účtů. U účtů bez zůstatků z výpisu (PayPal, starší importy CSV) nastav počáteční zůstatek zde, ať projekce začínají ze známého bodu.',
        'no_accounts' => 'Zatím žádné účty — přidej jeden importem výpisu z účtu.',
    ],

    'auto_import' => [
        'heading' => 'Automatický import',
        'label' => 'Automatický import ze složky pro odkládání',

        'active_html' => 'Složka pro odkládání je aktivní. Beatrax každých 5 minut prohledává <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code>, jestli nepřibyly nové soubory.',
        'inactive_html' => 'Když je zapnuto, Beatrax každých 5 minut prohledává <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code>, hledá soubory <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> a <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> a importuje je stejnou porovnávací linkou jako průvodce. Zpracované soubory se přesunou do <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, takže se nikdy neimportují dvakrát.',
    ],

    'aliases' => [
        'heading' => 'Aliasy',
        'intro' => 'Zkontroluj a uprav srozumitelné názvy, které jsi Beatraxu nastavil pro kryptické popisy z výpisů.',
        'manage' => 'Spravovat aliasy →',
    ],

    'tax_heading' => 'Daně',
    'shared_merchant_heading' => 'Sdílený seznam obchodníků',
    'data_backup_heading' => 'Data a zálohy',
    'install_heading' => 'Instalace',

    'about_updates' => [
        'heading' => 'O aktualizacích',
        'body' => 'Beatrax se po instalaci aktualizuje sám. Jakmile jednou nainstaluješ první verzi, další verze přicházejí přes banner přímo v aplikaci — na GitHub se vracet nemusíš. Kdyby se někdy aktualizaci nepodařilo použít, můžeš si nejnovější instalátor vždy stáhnout ručně ze stránky s vydáními.',
        'open_releases' => 'Otevřít stránku s vydáními →',
    ],

    'privacy' => [
        'heading' => 'Zásady ochrany osobních údajů',
        'body' => 'Beatrax drží tvoje finance na tvých vlastních zařízeních. Zásady vysvětlují, co to znamená, co odesílají volitelné online funkce a jak svoje data odstranit.',
        'open' => 'Přečíst zásady ochrany osobních údajů →',
        'url_hint' => 'Pokud se odkaz neotevře, navštiv:',
    ],

    'first_run_tour' => [
        'heading' => 'Úvodní prohlídka',
        'body' => 'Spusť průvodce nastavením znovu, pokud si chceš úvodní kroky projít ještě jednou.',
        'run_again' => 'Spustit průvodce nastavením znovu',
    ],

    'developer' => [
        'heading' => 'Vývojář',
        'label' => 'Vývojářská konzole v aplikaci',
        'help' => 'Zobrazí vývojářskou konzoli na /dev. Přepínač Pokročilé se při každém přihlášení vrátí zpět.',
        'aria' => 'Vývojářský režim',
    ],

    'errors' => [
        'currency_required' => 'Zvol prosím měnu.',
        'window_months' => 'Zvol rozmezí 2 až 60 měsíců.',
        'threshold' => 'Zvol práh 1%, 2%, 5%, 10%, 25% nebo 50%.',
        'amount' => 'Zadej částku od 0 € výš.',
        'period_day' => 'Zvol den od 1 do 28.',
        'currency_view' => 'Vyber jednu z dostupných možností.',
    ],
];
