<?php

declare(strict_types=1);

return [
    'page_title' => 'Pușculițe · Beatrax',
    'heading' => 'Pușculițe',
    'subtitle' => 'Solduri parțiale virtuale, desprinse din soldul real al contului.',
    'add_pot' => 'Adaugă pușculiță',

    'pot_fallback' => 'pușculiță',

    'empty' => [
        'heading' => 'Nicio pușculiță deocamdată',
        'body' => 'Creează solduri parțiale virtuale în orice cont, ca să îți organizezi banii fără un transfer bancar real.',
        'cta' => 'Adaugă prima pușculiță',
        'no_accounts_cta' => 'Importă un extras de cont',
    ],

    'common' => [
        'cancel' => 'Anulează',
        'amount' => 'Sumă',
        'note_optional' => 'Notă (opțional)',
    ],

    'actions' => [
        'fund' => 'Alimentează',
        'move' => 'Mută',
        'edit' => 'Editează',
        'withdraw' => 'Retrage',
        'archive' => 'Arhivează',
        'restore' => 'Restaurează',
    ],

    'recon' => [
        'over_allocated' => 'Pușculițele depășesc soldul real cu :amount — reechilibrează pentru a corecta',
        'real_balance' => 'Sold real:',
        'allocated' => 'Alocat:',
        'unallocated' => 'Nealocat:',
    ],

    'chip' => [
        'goal' => 'Obiectiv:',
        'goal_name_fallback' => 'Obiectiv',
        'category_fallback' => 'Categorie',
    ],

    'coverage' => [
        'spent' => 'cheltuit',
        'in_pot' => 'în pușculiță',
    ],

    'archive_confirm' => 'Arhivezi această pușculiță? Soldul de :amount se întoarce la nealocat.',
    'confirm_archive_aria' => 'Confirmă arhivarea pentru :name',
    'more_actions_aria' => 'Mai multe acțiuni pentru :name',

    'history' => [
        'show' => 'Arată istoricul ↓',
        'hide' => 'Ascunde istoricul ↑',
        'truncated' => 'Mișcări recente: :shown din :count',
    ],

    'movement' => [
        'fund' => 'Alimentare',
        'withdraw' => 'Retragere',
        'moved_from' => 'Mutat din :name',
        'moved_to' => 'Mutat în :name',
        'unreadable' => 'Înregistrat de o versiune mai nouă a Beatrax',
        'released_on_archive' => 'Eliberat la arhivare',
    ],

    'archived' => [
        'toggle' => 'Pușculiță arhivată (:count)|Pușculițe arhivate (:count)|Pușculițe arhivate (:count)',
        'badge' => 'Arhivată',
    ],

    'form' => [
        'create_title' => 'Creează o pușculiță',
        'edit_title' => 'Editează pușculița',
        'create_subtitle' => 'Denumește un sold parțial virtual dintr-un cont.',
        'edit_subtitle' => 'Actualizează numele sau asocierea acestei pușculițe.',
        'name' => 'Nume',
        'name_placeholder' => 'ex. Fond de vacanță',
        'account' => 'Cont',
        'select_account' => 'Alege un cont',
        'initial_amount' => 'Sumă inițială (opțional)',
        'initial_amount_help' => 'Suma se scade din soldul nealocat. Lasă gol pentru a crea o pușculiță goală.',
        'link_to' => 'Asociază cu (opțional)',
        'link_goal' => 'Obiectiv',
        'link_none' => 'Fără',
        'select_goal' => 'Alege un obiectiv',
        'save_pot' => 'Salvează pușculița',
        'save_changes' => 'Salvează modificările',
    ],

    'fund' => [
        'title' => 'Alimentează pușculița',
        'heading' => 'Alimentează :name',
        'submit' => 'Alimentează pușculița',
        'note_placeholder' => 'ex. Economii lunare',
        'available' => 'Disponibil de alocat: :amount (nealocat)',
    ],

    'move' => [
        'title' => 'Mută fonduri',
        'heading' => 'Mută din :name',
        'to' => 'Mută în',
        'select_pot' => 'Alege o pușculiță',
        'no_others_short' => 'Nicio altă pușculiță',
        'no_others' => 'Nicio altă pușculiță în acest cont',
        'submit' => 'Mută fonduri',
        'note_placeholder' => 'ex. Transfer pentru vacanță',
    ],

    'withdraw' => [
        'heading' => 'Retrage din :name',
        'note_placeholder' => 'ex. Retragere',
    ],

    'available_in' => 'Disponibil în :name: :amount',

    'errors' => [
        'enter_name' => 'Introdu un nume pentru această pușculiță.',
        'select_account' => 'Alege un cont pentru această pușculiță.',
        'amount_exceeds_unallocated_available' => 'Suma depășește soldul nealocat (:amount disponibil).',
        'amount_exceeds_pot_balance' => 'Suma depășește soldul din :name (:amount disponibil).',
        'generic' => 'Plicul nu a putut fi salvat. Verificați câmpurile și încercați din nou.',
        'amount_invalid' => 'Introduceți o sumă mai mare decât zero.',
        'goal_already_linked' => 'Acest obiectiv are deja un plic activ asociat. Arhivați-l mai întâi.',
        'account_cannot_hold_pots' => 'O pușculiță are nevoie de un cont care ține bani. Alege alt cont.',
        'select_target_pot' => 'Alege o pușculiță în care să muți.',
        'move_target_missing' => 'Această pușculiță nu mai este disponibilă. Alege alta.',
        'move_same_pot' => 'O pușculiță nu poate muta bani către ea însăși. Alege altă pușculiță.',
        'move_cross_account' => 'Pușculițele schimbă bani doar în cadrul aceluiași cont, iar :name este în contul :account.',
        'pot_missing' => 'Această pușculiță nu mai este disponibilă.',
        'operation_failed' => 'Nu a trecut. Nu s-au mutat bani — încearcă din nou.',
    ],

    'toast' => [
        'pot_created' => 'Pușculiță creată.',
        'pot_updated' => 'Pușculiță actualizată.',
        'pot_funded' => 'Pușculiță alimentată.',
        'withdrawn' => 'Retras din pușculiță.',
        'funds_moved' => 'Fonduri mutate.',
        'pot_archived' => 'Pușculiță arhivată.',
        'pot_restored' => 'Pușculiță restaurată.',
    ],
];
