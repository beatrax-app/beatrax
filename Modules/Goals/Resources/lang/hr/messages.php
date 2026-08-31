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
        'aria' => ':name: dovršeno :pct %',
    ],

    'card' => [
        'target_date' => 'Ciljani datum: :date',
    ],

    'projection' => [
        'target_reached' => 'Cilj je dosegnut',
        'closed_short' => 'Zatvoreno prije cilja',
        'add_contributions' => 'Dodaj uplate da vidiš projekciju',
        'not_enough_history' => 'Još nema dovoljno povijesti za projekciju datuma',
        'no_recent_contributions' => 'Nema nedavnih uplata na temelju kojih bi se radila projekcija',
        'too_far_to_date' => 'Pri ovom tempu predaleko za datum',
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
        'mark_complete_caption' => 'Označi',
        'archive' => 'Arhiviraj',
        'restore' => 'Vrati',
    ],

    'archived_disclosure' => 'Arhivirani cilj (:count)|Arhivirana cilja (:count)|Arhiviranih ciljeva (:count)',

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
        'date_invalid' => 'Odaberite stvarni datum.',
        'date_before_start' => 'Odaberi datum na dan početka cilja ili poslije.',
        'generic' => 'Cilj nije spremljen. Provjerite polja i pokušajte ponovno.',
        'amount' => 'Upiši ispravan iznos veći od nule.',
        'pot_linked_category' => 'Ova kasica povezana je s kategorijom. Prvo ukloni tu vezu na stranici Kasice.',
        'pot_already_linked' => 'Ova kasica već financira drugi cilj. Prvo tamo ukloni poveznicu.',
        'pot_missing' => 'Ta kasica više nije dostupna. Odaberi drugu ili ostavi ovaj cilj bez poveznice.',
    ],
];
