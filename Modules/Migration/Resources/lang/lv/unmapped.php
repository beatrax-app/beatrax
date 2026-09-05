<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Mērķis: :name',
        'category_goal' => 'Mērķis kategorijai :name',
        'schedule_untitled' => 'Nenosaukts plānotais darījums',
        'transaction' => 'Darījums: :name · :date · :amount',
        'transaction_unnamed' => 'Darījums',
        'amount_update' => 'Darījuma summas atjauninājums',
        'budget_history' => 'Budžeta vēsture valūtā :currency',
        'budget_file_currency' => 'Budžeta faila valūta',
        'budget_file_mode' => 'Budžeta faila režīms',
    ],

    'conflict' => [
        'budget_assignment' => 'Budžeta piešķīrums',
        'budget_for_month' => 'Budžets: :category · :month',
        'budget_for_category' => 'Budžets: :category',
        'category_name' => 'Kategorijas nosaukums',
        'category_name_of' => 'Kategorijas „:name” nosaukums',
        'account_name' => 'Konta nosaukums',
        'account_name_of' => 'Konta „:name” nosaukums',
        'transaction_amount' => 'Darījuma summa',
        'transaction_amount_of' => 'Summa: :name',
        'transaction_amount_of_dated' => 'Summa: :name · :date',
        'transaction_description' => 'Darījuma apraksts',
        'transaction_description_of' => 'Apraksts: :name',
        'transaction_description_of_dated' => 'Apraksts: :name · :date',
        'other' => 'Importētā vērtība',
    ],

    'reason' => [
        'fingerprint_collision' => 'Šis darījums sakrita ar jau reģistrētu darījumu (identisks pirksta nospiedums) un netika importēts.',

        // i18n-review: lv · reason.split_legs_without_category — Latvian selects
        // arm 0 for zero, and this line renders only above zero, so the genitive
        // plural ships unread. Whether a leg is "sadalījuma daļa" beside
        // ledger::detail.split, which says only "daļa", is the other question.
        'split_legs_without_category' => ':count sadalījuma daļu no :legs ir bez kategorijas, un sadalījuma daļu bez kategorijas nevar saglabāt. Darījums tika importēts pilnā apmērā un gaida kategorijā „:uncategorized”.|:count sadalījuma daļa no :legs ir bez kategorijas, un sadalījuma daļu bez kategorijas nevar saglabāt. Darījums tika importēts pilnā apmērā un gaida kategorijā „:uncategorized”.|:count sadalījuma daļas no :legs ir bez kategorijas, un sadalījuma daļu bez kategorijas nevar saglabāt. Darījums tika importēts pilnā apmērā un gaida kategorijā „:uncategorized”.',
        'split_sum_mismatch' => 'Sadalījuma daļu summa ir :legs, bet darījums ir :total, un sadalījumam ir precīzi jāatbilst savam darījumam. Darījums tika importēts pilnā apmērā, bez tā daļām.',
        'split_unstorable' => 'Beatrax nevar saglabāt šo sadalījumu tādā veidā, kāds tas ir, tāpēc darījums tika importēts atsevišķi, bez tā daļām.',
        'goal_without_target_date' => 'Šim mērķim nav mērķa datuma; Beatrax tas ir vajadzīgs, lai izveidotu uzkrājumu mērķi.',
        'goal_without_name' => 'Šim mērķim nav nosaukuma; Beatrax tas ir vajadzīgs, lai izveidotu uzkrājumu mērķi.',
        'goal_def_unsupported' => 'categories.goal_def izmanto neatbalstītu (ne plakanu) veidnes formu — mērķis netika importēts.',
        'budget_currency_mismatch' => ':count budžeta rindu netika importētas: jūsu budžeti tiek uzturēti valūtā :envelope, bet šis eksports budžetu veido valūtā :source.|:count budžeta rinda netika importēta: jūsu budžeti tiek uzturēti valūtā :envelope, bet šis eksports budžetu veido valūtā :source.|:count budžeta rindas netika importētas: jūsu budžeti tiek uzturēti valūtā :envelope, bet šis eksports budžetu veido valūtā :source.',
        'amount_apply_collision' => 'Avota jauno summu nevarēja piemērot — tā saduras ar cita darījuma pirksta nospiedumu (tas pats konts, datums, valūta un darījuma partneris). Atstāta nemainīga.',
        'amount_currency_mismatch' => 'Darījumu summas netika saskaņotas: šie darījumi tiek uzturēti valūtā :local, bet šis eksports tos norāda valūtā :source. Atstātas nemainītas.',
        'schedule_unsupported' => 'Plānotus un regulārus darījumus Beatrax vēl neprot izveidot no ārēja avota — saglabāts tikai kā piezīme, nevis kā aktīva sērija sadaļā Regulārie maksājumi.',
        'saved_report_unsupported' => 'Saglabātām atskaitēm un analīzes konfigurācijām Beatrax nav ekvivalenta.',
        'assumed_currency' => "Pieņemts: :currency — šajā eksportā netika atrasta neviena 'preferences.currencyCode' rinda.",
        'assumed_budget_type' => "Pieņemts: :mode — šajā eksportā netika atrasta neviena 'preferences.budgetType' rinda.",
        'changed_on_both_sides' => "Kopš pēdējā importa to ir mainījis gan avota fails, gan Beatrax.\nVietējā vērtība: :local\nAvots: :source\nPēdējoreiz importēts: :baseline",
        'take_source' => 'Jaunā eksporta vērtība tiks piemērota, tiklīdz apstiprināsiet — jūsu vietējā vērtība tiks aizstāta.',
        'keep_local' => 'Jūsu vietējā vērtība tiks paturēta — jaunā eksporta vērtība netiks piemērota.',
        'compared_values' => ":intro\nVietējā vērtība: :local · Avots: :source · Pēdējoreiz importēts: :baseline",
    ],

    'value' => [
        'none' => '(nav)',
        'quoted' => '„:value”',
    ],
];
