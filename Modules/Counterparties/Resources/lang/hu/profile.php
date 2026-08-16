<?php

declare(strict_types=1);

return [
    'page_title' => 'Partner',
    'fallback_account' => 'Számla',
    'fallback_counterparty' => 'Partner',

    'edit_display_name' => 'Megjelenítendő név szerkesztése',

    'hero_net_received' => 'Nettó bevétel',
    'hero_12mo_total' => '12 havi összeg',
    'hero_transactions' => 'Tranzakciók',
    'hero_first_seen' => 'Első előfordulás',

    'tabs' => [
        'overview' => 'Áttekintés',
        'transactions' => 'Tranzakciók',
        'chains' => 'Láncok',
        'aliases' => 'Álnevek',
        'transfers' => 'Átutalások',
        'entries' => 'Tételek',
        'payments' => 'Fizetések',
        'tax_years' => 'Adóévek',
    ],

    'tab_note_personal' => '— magánszemély kapcsolatoknál nincs fedezeti lánc',
    'tab_note_bank' => '— a banki díjpartner nem hoz létre fedezeti láncot',
    'tab_note_government' => '— állami partnereknél nincs fedezeti lánc',

    'recent_activity' => 'Legutóbbi tevékenység',
    'recurring' => 'Ismétlődő',
    'uncategorized' => 'Kategorizálatlan',
    'no_recent_transactions' => 'Ehhez a partnerhez még nincs rögzített tranzakció.',
    'see_all' => 'Mind a(z) :count megtekintése →',

    'bank' => [
        'fees_heading' => 'Banki díjak kategóriánként',
        'no_fees' => 'Ehhez a partnerhez még nincs rögzített díj.',
    ],

    'government' => [
        'intro' => 'Éves bontás minden olyan évre, amelyben volt tevékenység. Az aktuális év kiemelve.',
        'no_payments' => 'Ehhez a partnerhez még nincs rögzített fizetés.',
    ],

    'merchant' => [
        'categories' => 'Kategóriák',

        'categories_empty_html' => 'Még nincs kategória — a kategorizálatlan tranzakciók a <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Kategorizálás</a> oldalon jelennek meg.',
        'no_recurring' => 'Nem található ismétlődő minta.',
        'per_month_suffix' => '/hó',
        'funding_chain' => 'Fedezeti lánc',
        'no_funding_chain' => 'Még nem található fedezeti lánc. A fedezeti láncok feloldásához ASN- és PayPal-adatok importálása szükséges.',
        'open_chains' => 'Láncok áttekintésének megnyitása →',
    ],

    'personal' => [
        'contact' => 'Kapcsolat',
        'add_tag' => '+ Címke hozzáadása',
        'no_recurring' => 'Nem található ismétlődés — a magánutalások ritkán követnek szigorú ütemet; még a rendszeres lakbérosztozás dátuma is csúszhat.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Ez a partner még nincs felcímkézve',
        'not_labelled_body' => 'Az ismeretlenek felcímkézése segít abban, hogy az irányítópult pontos havi összegeket és fedezeti láncokat mutasson.',
        'label_cta' => 'Címkézd fel ezt a partnert',
    ],

    'support' => [
        'contact_help' => 'Kapcsolat és segítség',
        'sign_in_apply' => 'Bejelentkezés · igénylés',
        'your_rights' => 'Jogaid · tiltakozás',
        'cancel' => 'Lemondás',
        'help_support' => 'Súgó és támogatás',
        'cheaper_plan' => 'Olcsóbb csomag',
        'aria_gov' => 'Segítségkérés',
        'aria_merchant' => 'Támogatás és lemondás',
        'heading_gov' => 'Segítségkérés',
        'heading_merchant' => 'Támogatás és lemondás',
        'cancel_by_email' => 'Lemondás e-mailben',
    ],
];
