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
        'body' => 'Postavi ciljani iznos i datum da počneš da pratiš napredak štednje.',
        'add_first' => 'Dodaj svoj prvi cilj',
    ],

    'status' => [
        'overdue' => 'Kasni',
        'reached' => 'Dostignuto',
        'completed' => 'Završeno',
        'archived' => 'Arhivirano',
    ],

    'row' => [
        'edit' => 'Izmeni',
    ],

    'progress' => [
        'aria' => ':name: završeno :pct%',
    ],

    'card' => [
        'target_date' => 'Ciljani datum: :date',
    ],

    'projection' => [
        'target_reached' => 'Cilj je dostignut',
        'closed_short' => 'Zatvoreno pre cilja',
        'add_contributions' => 'Dodaj uplate da vidiš projekciju',
        'not_enough_history' => 'Još nema dovoljno istorije za projekciju datuma',
        'no_recent_contributions' => 'Nema nedavnih uplata na osnovu kojih bi se pravila projekcija',
        'est' => 'Proc. :date ·',
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
        'mark_complete' => 'Označi kao završeno',
        'archive' => 'Arhiviraj',
        'restore' => 'Vrati',
    ],

    'archived_disclosure' => 'Arhivirani ciljevi (:count)',

    'form' => [
        'title_edit' => 'Izmeni cilj',
        'title_create' => 'Napravi cilj štednje',
        'subtitle_edit' => 'Ažuriraj naziv, ciljani iznos, datum ili povezanu kasicu.',
        'subtitle_create' => 'Postavi ciljani iznos i datum da pratiš napredak štednje.',
        'name' => 'Naziv',
        'name_placeholder' => 'npr. Rezerva za hitne slučajeve',
        'target_amount' => 'Ciljani iznos (:currency)',
        'target_date' => 'Ciljani datum',
        'linked_pot' => 'Povezana kasica (opciono)',
        'no_pot' => 'Bez kasice — koristi praćenje prenosa',
        'linked_pot_help' => 'Kad je povezana, stanje kasice određuje napredak ovog cilja.',
        'save_changes' => 'Sačuvaj izmene',
        'save_goal' => 'Sačuvaj cilj',
        'close' => 'Zatvori',
    ],

    'summary' => [
        'see_all' => 'Prikaži sve →',
        'no_goals' => 'Još nema ciljeva.',
        'add_first' => 'Dodaj svoj prvi cilj →',
    ],

    'notices' => [
        'goal_created' => 'Cilj je napravljen.',
        'goal_updated' => 'Cilj je ažuriran.',
        'goal_marked_complete' => 'Cilj je označen kao završen.',
        'goal_archived' => 'Cilj je arhiviran.',
        'goal_restored' => 'Cilj je vraćen.',
    ],

    'errors' => [
        'name' => 'Unesi naziv svog cilja.',
        'date' => 'Izaberi ciljani datum.',
        'date_invalid' => 'Izaberite stvarni datum.',
        'generic' => 'Cilj nije sačuvan. Proverite polja i pokušajte ponovo.',
        'amount' => 'Unesi ispravan iznos veći od nule.',
        'pot_linked_category' => 'Ova kasica je povezana sa kategorijom. Prvo ukloni tu vezu na stranici Kasice.',
    ],
];
