<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Tikslai',
        'subtitle' => 'Stebėk, kaip artėji prie taupymo tikslų.',
        'add_goal' => 'Pridėti tikslą',
    ],

    'empty' => [
        'heading' => 'Tikslų dar nėra',
        'body' => 'Nurodyk tikslo sumą ir datą, kad pradėtum stebėti taupymo eigą.',
        'add_first' => 'Pridėk pirmą tikslą',
    ],

    'status' => [
        'overdue' => 'Vėluoja',
        'reached' => 'Pasiektas',
        'completed' => 'Užbaigtas',
        'archived' => 'Archyvuotas',
    ],

    'row' => [
        'edit' => 'Redaguoti',
    ],

    'progress' => [
        'aria' => ':name: įvykdyta :pct %',
    ],

    'projection' => [
        'target_reached' => 'Tikslas pasiektas',
        'add_contributions' => 'Pridėk įnašų, kad matytum prognozę',
        'building' => 'Rengiama prognozė…',
        'est' => 'Apytiksliai :date ·',
        'projection_note' => '(prognozė)',
        'projected' => 'Prognozuojama: :date',
    ],

    'archive' => [
        'confirm_question' => 'Archyvuoti šį tikslą?',
        'close' => 'Uždaryti',
        'confirm_aria' => 'Patvirtinti tikslo :name archyvavimą',
        'archive' => 'Archyvuoti',
    ],

    'actions' => [
        'more_aria' => 'Daugiau veiksmų su tikslu :name',
        'mark_complete' => 'Žymėti kaip užbaigtą',
        'archive' => 'Archyvuoti',
        'restore' => 'Atkurti',
    ],

    'archived_disclosure' => 'Archyvuoti tikslai (:count)',

    'form' => [
        'title_edit' => 'Redaguoti tikslą',
        'title_create' => 'Sukurti taupymo tikslą',
        'subtitle_edit' => 'Atnaujink pavadinimą, tikslo sumą, datą arba susietą sąskaitą.',
        'subtitle_create' => 'Nurodyk tikslo sumą ir datą, kad galėtum stebėti taupymo eigą.',
        'name' => 'Pavadinimas',
        'name_placeholder' => 'pvz. Atsargų fondas',
        'target_amount' => 'Tikslo suma (:currency)',
        'target_date' => 'Tikslo data',
        'savings_account' => 'Taupomoji sąskaita (neprivaloma)',
        'no_account' => 'Sąskaitos nėra — stebėti rankiniu būdu',
        'linked_pot' => 'Susieta taupyklė (neprivaloma)',
        'select_account_first' => 'Pirmiausia pasirink sąskaitą',
        'no_pot' => 'Taupyklės nėra — stebėti pagal pavedimus',
        'linked_pot_help' => 'Susiejus, tikslo eigą lemia taupyklės likutis.',
        'save_changes' => 'Išsaugoti pakeitimus',
        'save_goal' => 'Išsaugoti tikslą',
        'close' => 'Uždaryti',
    ],

    'summary' => [
        'see_all' => 'Rodyti visus →',
        'no_goals' => 'Tikslų dar nėra.',
        'add_first' => 'Pridėk pirmą tikslą →',
    ],

    'notices' => [
        'goal_created' => 'Tikslas sukurtas.',
        'goal_updated' => 'Tikslas atnaujintas.',
        'goal_marked_complete' => 'Tikslas pažymėtas kaip užbaigtas.',
        'goal_archived' => 'Tikslas archyvuotas.',
        'goal_restored' => 'Tikslas atkurtas.',
    ],

    'errors' => [
        'name' => 'Įvesk tikslo pavadinimą.',
        'date' => 'Pasirink tikslo datą.',
        'amount' => 'Įvesk tinkamą sumą, didesnę už nulį.',
        'pot_linked_category' => 'Ši taupyklė susieta su kategorija. Pirmiausia pašalink tą ryšį Taupyklių puslapyje.',
        'account_not_owned' => 'Sąskaita nepriklauso prisijungusiam naudotojui.',
    ],
];
