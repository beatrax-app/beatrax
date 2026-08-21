<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Ciljevi',
        'subtitle' => 'Prati napredak prema svojim ciljevima štednje.',
        'add_goal' => 'Dodaj cilj',
    ],

    'empty' => [
        'heading' => 'Još nema ciljeva',
        'body' => 'Postavi ciljani iznos i datum da počneš pratiti napredak štednje.',
        'add_first' => 'Dodaj svoj prvi cilj',
    ],

    'status' => [
        'overdue' => 'Kasni',
        'reached' => 'Dosegnuto',
        'completed' => 'Dovršeno',
        'archived' => 'Arhivirano',
    ],

    'row' => [
        'edit' => 'Uredi',
    ],

    'progress' => [
        'aria' => ':name: dovršeno :pct%',
    ],

    'projection' => [
        'target_reached' => 'Cilj je dosegnut',
        'add_contributions' => 'Dodaj uplate da vidiš projekciju',
        'not_enough_history' => 'Još nema dovoljno povijesti za projekciju datuma',
        'est' => 'Procj. :date ·',
        'projection_note' => '(projekcija)',
        'projected' => 'Projekcija: :date',
    ],

    'archive' => [
        'confirm_question' => 'Arhivirati ovaj cilj?',
        'close' => 'Zatvori',
        'confirm_aria' => 'Potvrdi arhiviranje cilja :name',
        'archive' => 'Arhiviraj',
    ],

    'actions' => [
        'more_aria' => 'Više radnji za :name',
        'mark_complete' => 'Označi kao dovršeno',
        'archive' => 'Arhiviraj',
        'restore' => 'Vrati',
    ],

    'archived_disclosure' => 'Arhivirani ciljevi (:count)',

    'form' => [
        'title_edit' => 'Uredi cilj',
        'title_create' => 'Stvori cilj štednje',
        'subtitle_edit' => 'Ažuriraj naziv, ciljani iznos, datum ili povezanu kasicu.',
        'subtitle_create' => 'Postavi ciljani iznos i datum da pratiš napredak štednje.',
        'name' => 'Naziv',
        'name_placeholder' => 'npr. Sigurnosna ušteđevina',
        'target_amount' => 'Ciljani iznos (:currency)',
        'target_date' => 'Ciljani datum',
        'linked_pot' => 'Povezana kasica (neobavezno)',
        'no_pot' => 'Bez kasice — koristi praćenje prijenosa',
        'linked_pot_help' => 'Kad je povezana, stanje kasice određuje napredak ovog cilja.',
        'save_changes' => 'Spremi promjene',
        'save_goal' => 'Spremi cilj',
        'close' => 'Zatvori',
    ],

    'summary' => [
        'see_all' => 'Prikaži sve →',
        'no_goals' => 'Još nema ciljeva.',
        'add_first' => 'Dodaj svoj prvi cilj →',
    ],

    'notices' => [
        'goal_created' => 'Cilj je stvoren.',
        'goal_updated' => 'Cilj je ažuriran.',
        'goal_marked_complete' => 'Cilj je označen kao dovršen.',
        'goal_archived' => 'Cilj je arhiviran.',
        'goal_restored' => 'Cilj je vraćen.',
    ],

    'errors' => [
        'name' => 'Upiši naziv svojeg cilja.',
        'date' => 'Odaberi ciljani datum.',
        'amount' => 'Upiši ispravan iznos veći od nule.',
        'pot_linked_category' => 'Ova kasica povezana je s kategorijom. Prvo ukloni tu vezu na stranici Kasice.',
    ],
];
